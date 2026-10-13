<?php

namespace App\Inventory\Entity;

use App\Inventory\Repository\StockDocumentRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A warehouse document (PZ, WZ or MM). While it is a draft it changes nothing;
 * posting it moves the stock and freezes the document for good.
 */
#[ORM\Entity(repositoryClass: StockDocumentRepository::class)]
#[ORM\Table(name: 'inventory_document')]
class StockDocument
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 2)]
    private DocumentType $type;

    #[ORM\Column(length: 10)]
    private DocumentStatus $status = DocumentStatus::Draft;

    /** Assigned when the document is posted, e.g. PZ/2026/10/0001. */
    #[ORM\Column(length: 30, unique: true, nullable: true)]
    private ?string $number = null;

    /** Where goods arrive (PZ) or leave from (WZ, MM). */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private Warehouse $warehouse;

    /** Destination of a transfer (MM only). */
    #[ORM\ManyToOne]
    private ?Warehouse $targetWarehouse = null;

    /** Supplier (PZ) or customer (WZ); an id from the Partner module. */
    #[ORM\Column(nullable: true)]
    private ?int $partnerId = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $documentDate;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $note = null;

    /** @var Collection<int, StockDocumentLine> */
    #[ORM\OneToMany(targetEntity: StockDocumentLine::class, mappedBy: 'document', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $lines;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $postedAt = null;

    public function __construct(
        DocumentType $type,
        Warehouse $warehouse,
        \DateTimeImmutable $documentDate,
        \DateTimeImmutable $now,
        ?Warehouse $targetWarehouse = null,
    ) {
        if (DocumentType::Transfer === $type && (null === $targetWarehouse || $targetWarehouse === $warehouse)) {
            throw new \DomainException('Przesunięcie wymaga innego magazynu docelowego niż źródłowy.');
        }
        if (DocumentType::Transfer !== $type && null !== $targetWarehouse) {
            throw new \DomainException('Tylko przesunięcie ma magazyn docelowy.');
        }

        $this->type = $type;
        $this->warehouse = $warehouse;
        $this->targetWarehouse = $targetWarehouse;
        $this->documentDate = $documentDate->setTime(0, 0);
        $this->createdAt = $now;
        $this->lines = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getType(): DocumentType
    {
        return $this->type;
    }

    public function getStatus(): DocumentStatus
    {
        return $this->status;
    }

    public function isDraft(): bool
    {
        return DocumentStatus::Draft === $this->status;
    }

    public function getNumber(): ?string
    {
        return $this->number;
    }

    /**
     * What to call the document on screen: its number, or a placeholder while it is a draft.
     */
    public function title(): string
    {
        return $this->number ?? \sprintf('%s (szkic nr %d)', $this->type->value, (int) $this->id);
    }

    public function getWarehouse(): Warehouse
    {
        return $this->warehouse;
    }

    public function getTargetWarehouse(): ?Warehouse
    {
        return $this->targetWarehouse;
    }

    public function getPartnerId(): ?int
    {
        return $this->partnerId;
    }

    public function setPartnerId(?int $partnerId): void
    {
        $this->assertDraft();
        $this->partnerId = $partnerId;
    }

    public function getDocumentDate(): \DateTimeImmutable
    {
        return $this->documentDate;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function setNote(?string $note): void
    {
        $this->assertDraft();
        $note = trim((string) $note);
        $this->note = '' === $note ? null : $note;
    }

    /**
     * @return Collection<int, StockDocumentLine>
     */
    public function getLines(): Collection
    {
        return $this->lines;
    }

    /**
     * @param int $quantity in thousandths of the product's unit
     */
    public function addLine(int $productId, int $quantity): StockDocumentLine
    {
        $this->assertDraft();

        $line = new StockDocumentLine($this, $productId, $quantity);
        $this->lines->add($line);

        return $line;
    }

    public function removeLine(StockDocumentLine $line): void
    {
        $this->assertDraft();
        $this->lines->removeElement($line);
    }

    /**
     * Total quantity per product, in case the same product appears on several lines.
     *
     * @return array<int, int> product id => quantity in thousandths
     */
    public function quantitiesByProduct(): array
    {
        $totals = [];
        foreach ($this->lines as $line) {
            $totals[$line->getProductId()] = ($totals[$line->getProductId()] ?? 0) + $line->getQuantity();
        }
        ksort($totals);

        return $totals;
    }

    public function markPosted(string $number, \DateTimeImmutable $now): void
    {
        $this->assertDraft();
        $this->number = $number;
        $this->status = DocumentStatus::Posted;
        $this->postedAt = $now;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getPostedAt(): ?\DateTimeImmutable
    {
        return $this->postedAt;
    }

    private function assertDraft(): void
    {
        if (!$this->isDraft()) {
            throw new \DomainException('Zaksięgowanego dokumentu nie można zmieniać.');
        }
    }
}
