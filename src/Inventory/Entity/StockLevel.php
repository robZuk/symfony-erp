<?php

namespace App\Inventory\Entity;

use App\Inventory\Repository\StockLevelRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Current quantity of one product in one warehouse. It is the running total of
 * StockMovement rows and is only ever changed together with them, by StockPosting.
 */
#[ORM\Entity(repositoryClass: StockLevelRepository::class)]
#[ORM\Table(name: 'inventory_stock_level')]
#[ORM\UniqueConstraint(name: 'uniq_stock_level_warehouse_product', columns: ['warehouse_id', 'product_id'])]
class StockLevel
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

    /** On hand, in thousandths of the product's unit. */
    #[ORM\Column]
    private int $quantity = 0;

    /** Part of the quantity promised to confirmed sales orders. */
    #[ORM\Column]
    private int $reserved = 0;

    public function __construct(Warehouse $warehouse, int $productId)
    {
        $this->warehouse = $warehouse;
        $this->productId = $productId;
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

    public function getReserved(): int
    {
        return $this->reserved;
    }

    /**
     * What can still be issued or promised to someone.
     */
    public function available(): int
    {
        return $this->quantity - $this->reserved;
    }

    /**
     * @param int $delta positive when goods arrive, negative when they leave
     */
    public function apply(int $delta): void
    {
        if ($delta < 0 && -$delta > $this->available()) {
            throw new \DomainException('Stan magazynowy nie może spaść poniżej ilości zarezerwowanej.');
        }

        $this->quantity += $delta;
    }
}
