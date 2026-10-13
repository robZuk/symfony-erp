<?php

namespace App\Inventory\Entity;

use App\Inventory\Repository\StockMovementRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * One entry in the stock ledger. Movements are only ever added, never changed or removed,
 * so the history of every product can always be reconstructed.
 */
#[ORM\Entity(repositoryClass: StockMovementRepository::class)]
#[ORM\Table(name: 'inventory_stock_movement')]
#[ORM\Index(name: 'idx_stock_movement_product_warehouse', columns: ['product_id', 'warehouse_id'])]
class StockMovement
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private Warehouse $warehouse;

    #[ORM\Column]
    private int $productId;

    /** Signed, in thousandths: positive for goods in, negative for goods out. */
    #[ORM\Column]
    private int $quantity;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private StockDocument $document;

    #[ORM\Column]
    private \DateTimeImmutable $occurredAt;

    public function __construct(
        Warehouse $warehouse,
        int $productId,
        int $quantity,
        StockDocument $document,
        \DateTimeImmutable $occurredAt,
    ) {
        $this->warehouse = $warehouse;
        $this->productId = $productId;
        $this->quantity = $quantity;
        $this->document = $document;
        $this->occurredAt = $occurredAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getWarehouse(): Warehouse
    {
        return $this->warehouse;
    }

    public function getProductId(): int
    {
        return $this->productId;
    }

    public function getQuantity(): int
    {
        return $this->quantity;
    }

    public function getDocument(): StockDocument
    {
        return $this->document;
    }

    public function getOccurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
