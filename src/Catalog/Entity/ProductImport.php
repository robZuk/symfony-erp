<?php

namespace App\Catalog\Entity;

use App\Catalog\Exchange\ImportProblem;
use App\Catalog\Exchange\PriceListFormat;
use App\Catalog\Repository\ProductImportRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A price list uploaded to update the catalog, and what came of it.
 *
 * The file is kept in the database until the background worker has read it: the web pod that
 * received it and the worker pod that imports it do not share a disk. It is dropped once the
 * import is over; the report stays.
 */
#[ORM\Entity(repositoryClass: ProductImportRepository::class)]
#[ORM\Table(name: 'catalog_product_import')]
class ProductImport
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** @var resource|string|null */
    #[ORM\Column(type: Types::BLOB, nullable: true)]
    private mixed $content;

    #[ORM\Column(length: 10)]
    private ImportStatus $status = ImportStatus::Pending;

    #[ORM\Column]
    private int $created = 0;

    #[ORM\Column]
    private int $updated = 0;

    #[ORM\Column]
    private int $unchanged = 0;

    /** @var list<array{line: ?int, column: ?string, message: string}> */
    #[ORM\Column(type: Types::JSON)]
    private array $problems = [];

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    public function __construct(
        #[ORM\Column(length: 255)]
        private string $fileName,
        #[ORM\Column(length: 3)]
        private PriceListFormat $format,
        string $content,
        /** Who uploaded it, as the activity log names them. */
        #[ORM\Column(length: 120)]
        private string $requestedBy,
        #[ORM\Column]
        private \DateTimeImmutable $createdAt,
    ) {
        $this->content = $content;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getFileName(): string
    {
        return $this->fileName;
    }

    public function getFormat(): PriceListFormat
    {
        return $this->format;
    }

    /**
     * The uploaded file as a stream, read from the start; null once the import is over.
     *
     * @return resource|null
     */
    public function contentStream()
    {
        if (null === $this->content) {
            return null;
        }
        if (\is_string($this->content)) {
            $stream = fopen('php://temp', 'r+') ?: throw new \RuntimeException('Cannot open a temporary stream.');
            fwrite($stream, $this->content);
            rewind($stream);

            return $stream;
        }

        rewind($this->content);

        return $this->content;
    }

    public function getStatus(): ImportStatus
    {
        return $this->status;
    }

    public function isPending(): bool
    {
        return ImportStatus::Pending === $this->status;
    }

    public function getCreated(): int
    {
        return $this->created;
    }

    public function getUpdated(): int
    {
        return $this->updated;
    }

    public function getUnchanged(): int
    {
        return $this->unchanged;
    }

    /**
     * @return list<array{line: ?int, column: ?string, message: string}>
     */
    public function getProblems(): array
    {
        return $this->problems;
    }

    public function getRequestedBy(): string
    {
        return $this->requestedBy;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getFinishedAt(): ?\DateTimeImmutable
    {
        return $this->finishedAt;
    }

    public function succeed(int $created, int $updated, int $unchanged, \DateTimeImmutable $now): void
    {
        $this->status = ImportStatus::Done;
        [$this->created, $this->updated, $this->unchanged] = [$created, $updated, $unchanged];
        $this->finish($now);
    }

    /**
     * @param list<ImportProblem> $problems
     */
    public function fail(array $problems, \DateTimeImmutable $now): void
    {
        $this->status = ImportStatus::Failed;
        $this->problems = array_map(static fn (ImportProblem $problem): array => $problem->toArray(), $problems);
        $this->finish($now);
    }

    private function finish(\DateTimeImmutable $now): void
    {
        $this->finishedAt = $now;
        // The file is not needed any more and may be large
        $this->content = null;
    }
}
