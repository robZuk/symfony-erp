<?php

namespace App\Catalog\Exchange;

use App\Catalog\Entity\Product;
use App\Catalog\Entity\ProductImport;
use App\Catalog\Repository\ProductRepository;
use App\Shared\Audit\ActivityLog;
use App\Shared\Persistence\Transactional;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Updates the catalog from a price list: adds the products it does not have yet and changes the
 * ones whose data differs, matched by SKU. All or nothing: one wrong row and no product changes,
 * and the report lists every problem with its line, so the file can be fixed in one go.
 *
 * Two passes. The first reads and checks every row, against the same rules as the product form
 * (validated on a scratch product, the real ones are not touched yet). The second, only when there
 * is nothing to complain about, writes all changes in one transaction together with the events
 * that bring the search index up to date.
 */
final class ProductImporter
{
    public const int MAX_ROWS = 10_000;
    private const int MAX_PROBLEMS = 100;

    /** Product properties checked against the entity's own rules, and the column each comes from. */
    private const array VALIDATED = [
        'sku' => PriceListColumns::SKU,
        'name' => PriceListColumns::NAME,
        'netPrice' => PriceListColumns::NET_PRICE,
        'minimumStock' => PriceListColumns::MINIMUM_STOCK,
    ];

    public function __construct(
        private readonly PriceListReader $reader,
        private readonly ProductRepository $products,
        private readonly ValidatorInterface $validator,
        private readonly Transactional $transactional,
        private readonly EntityManagerInterface $entityManager,
        private readonly ActivityLog $activity,
        private readonly ClockInterface $clock,
    ) {
    }

    public function run(ProductImport $import): void
    {
        $stream = $import->contentStream();
        // Already imported: a message delivered twice does nothing the second time
        if (null === $stream || !$import->isPending()) {
            return;
        }

        try {
            [$rows, $problems] = $this->check($stream, $import->getFormat());
        } catch (InvalidPriceList $exception) {
            [$rows, $problems] = [[], [new ImportProblem(null, null, $exception->getMessage())]];
        }

        if ([] === $rows && [] === $problems) {
            $problems[] = new ImportProblem(null, null, 'W pliku nie ma żadnego produktu.');
        }

        if ([] !== $problems) {
            $import->fail(self::capped($problems), $this->clock->now());
            $this->entityManager->flush();

            return;
        }

        $this->transactional->run(function () use ($import, $rows): void {
            [$changed, $created, $updated, $unchanged] = $this->apply($rows);
            $this->products->saveAll($changed);
            $import->succeed($created, $updated, $unchanged, $this->clock->now());
            $this->activity->record('catalog.product.imported', \sprintf(
                'Zaimportowano cennik %s (%s): %d nowych, %d zmienionych, %d bez zmian.',
                $import->getFileName(),
                $import->getRequestedBy(),
                $created,
                $updated,
                $unchanged,
            ));
        });
    }

    /**
     * @param resource $stream
     *
     * @return array{list<PriceListRow>, list<ImportProblem>}
     */
    private function check($stream, PriceListFormat $format): array
    {
        $rows = [];
        $problems = [];
        $lineOfSku = [];
        $count = 0;

        foreach ($this->reader->rows($stream, $format) as $line => $raw) {
            if (++$count > self::MAX_ROWS) {
                throw new InvalidPriceList(\sprintf('Plik ma więcej niż %d produktów. Podziel go na mniejsze.', self::MAX_ROWS));
            }

            // Checked on the SKU as written, before the rest of the row: a duplicate of a row that is
            // wrong for another reason is reported now, not after that row has been fixed
            $sku = mb_strtoupper(trim($raw[PriceListColumns::SKU] ?? ''));
            if ('' !== $sku && isset($lineOfSku[$sku])) {
                $problems[] = new ImportProblem($line, PriceListColumns::SKU, \sprintf('Indeks %s jest już w wierszu %d.', $sku, $lineOfSku[$sku]));

                continue;
            }
            if ('' !== $sku) {
                $lineOfSku[$sku] = $line;
            }

            $row = PriceListRow::parse($line, $raw);
            if (\is_array($row)) {
                array_push($problems, ...$row);

                continue;
            }

            // The same rules as the product form (SKU format, lengths), on a product that is never saved
            $scratch = new Product();
            $row->applyTo($scratch);
            foreach (self::VALIDATED as $property => $column) {
                foreach ($this->validator->validateProperty($scratch, $property) as $violation) {
                    $problems[] = new ImportProblem($line, $column, (string) $violation->getMessage());
                }
            }

            $rows[] = $row;
        }

        return [$rows, $problems];
    }

    /**
     * @param list<PriceListRow> $rows
     *
     * @return array{list<Product>, int, int, int} products to save, and how many were created, updated and left as they were
     */
    private function apply(array $rows): array
    {
        $existing = [];
        foreach (array_chunk(array_map(static fn (PriceListRow $row): string => $row->sku, $rows), 500) as $skus) {
            foreach ($this->products->findBy(['sku' => $skus]) as $product) {
                $existing[$product->getSku()] = $product;
            }
        }

        $changed = [];
        $created = $updated = $unchanged = 0;
        foreach ($rows as $row) {
            $product = $existing[$row->sku] ?? null;
            if (null === $product) {
                $product = new Product();
                $row->applyTo($product);
                $changed[] = $product;
                ++$created;

                continue;
            }

            $before = self::snapshot($product);
            $row->applyTo($product);
            if ($before === self::snapshot($product)) {
                ++$unchanged;
            } else {
                $changed[] = $product;
                ++$updated;
            }
        }

        return [$changed, $created, $updated, $unchanged];
    }

    /**
     * @return list<mixed>
     */
    private static function snapshot(Product $product): array
    {
        return [$product->getName(), $product->getKind(), $product->getUnit(), $product->getNetPrice(), $product->getVatRate(), $product->getMinimumStock(), $product->isActive()];
    }

    /**
     * @param list<ImportProblem> $problems
     *
     * @return list<ImportProblem>
     */
    private static function capped(array $problems): array
    {
        if (\count($problems) <= self::MAX_PROBLEMS) {
            return $problems;
        }

        return [
            ...\array_slice($problems, 0, self::MAX_PROBLEMS),
            new ImportProblem(null, null, \sprintf('…i %d innych błędów. Popraw powyższe i wgraj plik ponownie.', \count($problems) - self::MAX_PROBLEMS)),
        ];
    }
}
