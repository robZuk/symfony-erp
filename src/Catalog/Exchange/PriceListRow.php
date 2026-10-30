<?php

namespace App\Catalog\Exchange;

use App\Catalog\Entity\Product;
use App\Catalog\Entity\ProductKind;
use App\Catalog\Entity\UnitOfMeasure;
use App\Shared\Tax\VatRate;

/**
 * One row of a price list, read from text the way people type it into a spreadsheet:
 * "34,90" and "34.90", "23" and "23%", "usługa" and "service" all mean the same.
 *
 * An optional column left empty keeps the product's current value; a new product gets the default.
 */
final readonly class PriceListRow
{
    private function __construct(
        public int $line,
        public string $sku,
        public string $name,
        /** In grosze. */
        public int $netPrice,
        public VatRate $vatRate,
        public ?ProductKind $kind,
        public ?UnitOfMeasure $unit,
        /** In thousandths of the unit. */
        public ?int $minimumStock,
        public ?bool $active,
    ) {
    }

    /**
     * @param array<string, string> $raw column => text, as PriceListReader gives it
     *
     * @return self|list<ImportProblem> the row, or what is wrong with it
     */
    public static function parse(int $line, array $raw): self|array
    {
        $problems = [];
        $problem = static function (string $column, string $message) use ($line, &$problems): null {
            $problems[] = new ImportProblem($line, $column, $message);

            return null;
        };
        $text = static fn (string $column): string => trim($raw[$column] ?? '');

        $sku = mb_strtoupper($text(PriceListColumns::SKU));
        if ('' === $sku) {
            $problem(PriceListColumns::SKU, 'Brak indeksu.');
        }

        $name = $text(PriceListColumns::NAME);
        if ('' === $name) {
            $problem(PriceListColumns::NAME, 'Brak nazwy.');
        }

        $netPrice = self::money($text(PriceListColumns::NET_PRICE))
            ?? $problem(PriceListColumns::NET_PRICE, \sprintf('„%s” to nie jest cena. Podaj kwotę netto, np. 34,90.', $text(PriceListColumns::NET_PRICE)));

        $vatRate = self::vatRate($text(PriceListColumns::VAT_RATE))
            ?? $problem(PriceListColumns::VAT_RATE, \sprintf('„%s” to nie jest stawka VAT. Dozwolone: 23, 8, 5, 0, zw.', $text(PriceListColumns::VAT_RATE)));

        $kind = '' === $text(PriceListColumns::KIND) ? null : (self::kind($text(PriceListColumns::KIND))
            ?? $problem(PriceListColumns::KIND, \sprintf('„%s” to nie jest rodzaj. Dozwolone: towar, usługa.', $text(PriceListColumns::KIND))));

        $unit = '' === $text(PriceListColumns::UNIT) ? null : (self::unit($text(PriceListColumns::UNIT))
            ?? $problem(PriceListColumns::UNIT, \sprintf('Nieznana jednostka „%s”. Dozwolone: %s.', $text(PriceListColumns::UNIT), implode(', ', array_map(static fn (UnitOfMeasure $unit): string => $unit->label(), UnitOfMeasure::cases())))));

        $minimumStock = '' === $text(PriceListColumns::MINIMUM_STOCK) ? null : (self::quantity($text(PriceListColumns::MINIMUM_STOCK))
            ?? $problem(PriceListColumns::MINIMUM_STOCK, \sprintf('„%s” to nie jest ilość.', $text(PriceListColumns::MINIMUM_STOCK))));

        $active = '' === $text(PriceListColumns::ACTIVE) ? null : (self::yesNo($text(PriceListColumns::ACTIVE))
            ?? $problem(PriceListColumns::ACTIVE, \sprintf('„%s”: wpisz tak albo nie.', $text(PriceListColumns::ACTIVE))));

        if ([] !== $problems || null === $netPrice || null === $vatRate) {
            return $problems;
        }

        return new self($line, $sku, $name, $netPrice, $vatRate, $kind, $unit, $minimumStock, $active);
    }

    /**
     * Writes the row onto a product, new or existing.
     */
    public function applyTo(Product $product): void
    {
        $product->setSku($this->sku);
        $product->setName($this->name);
        $product->setNetPrice($this->netPrice);
        $product->setVatRate($this->vatRate);
        if (null !== $this->kind) {
            $product->setKind($this->kind);
        }
        if (null !== $this->unit) {
            $product->setUnit($this->unit);
        }
        if (null !== $this->minimumStock) {
            $product->setMinimumStock($this->minimumStock);
        }
        if (null !== $this->active) {
            $product->setActive($this->active);
        }
    }

    /**
     * "34,90", "34.90", "1 234,5", "1234" => grosze; null for anything else, a negative amount included.
     */
    private static function money(string $text): ?int
    {
        $text = str_replace([' ', "\u{00A0}", 'zł', 'PLN'], '', $text);
        if (1 !== preg_match('/^(\d{1,9})(?:[.,](\d{1,2}))?$/', $text, $parts)) {
            return null;
        }

        return (int) $parts[1] * 100 + (int) str_pad($parts[2] ?? '', 2, '0');
    }

    /**
     * "2,5", "10" => thousandths; zero is allowed (not watched).
     */
    private static function quantity(string $text): ?int
    {
        $text = str_replace([' ', "\u{00A0}"], '', $text);
        if (1 !== preg_match('/^(\d{1,9})(?:[.,](\d{1,3}))?$/', $text, $parts)) {
            return null;
        }

        return (int) $parts[1] * 1000 + (int) str_pad($parts[2] ?? '', 3, '0');
    }

    private static function vatRate(string $text): ?VatRate
    {
        $text = rtrim(mb_strtolower(str_replace(' ', '', $text)), '%.');

        return VatRate::tryFrom($text);
    }

    private static function kind(string $text): ?ProductKind
    {
        return match (mb_strtolower($text)) {
            'towar', 'goods', 'produkt' => ProductKind::Goods,
            'usługa', 'usluga', 'service' => ProductKind::Service,
            default => null,
        };
    }

    private static function unit(string $text): ?UnitOfMeasure
    {
        $text = mb_strtolower($text);
        foreach (UnitOfMeasure::cases() as $unit) {
            if ($text === $unit->value || $text === mb_strtolower($unit->label())) {
                return $unit;
            }
        }

        return null;
    }

    private static function yesNo(string $text): ?bool
    {
        return match (mb_strtolower($text)) {
            'tak', 't', '1', 'yes', 'y', 'true' => true,
            'nie', 'n', '0', 'no', 'false' => false,
            default => null,
        };
    }
}
