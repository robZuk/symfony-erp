<?php

namespace App\Sales\Entity;

use App\Shared\Money\Money;
use App\Shared\Tax\VatRate;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'sales_order_line')]
class SalesOrderLine
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'lines')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private SalesOrder $order;

    /** An id from the Catalog module. */
    #[ORM\Column]
    private int $productId;

    /** In thousandths of the product's unit. */
    #[ORM\Column]
    private int $quantity;

    /** Agreed net price for one unit, in grosze. Copied here so later catalog changes do not alter the order. */
    #[ORM\Column]
    private int $unitNetPrice;

    #[ORM\Column(length: 5)]
    private VatRate $vatRate;

    public function __construct(SalesOrder $order, int $productId, int $quantity, int $unitNetPrice, VatRate $vatRate)
    {
        if ($quantity <= 0) {
            throw new \DomainException('Ilość musi być większa od zera.');
        }
        if ($unitNetPrice < 0) {
            throw new \DomainException('Cena nie może być ujemna.');
        }

        $this->order = $order;
        $this->productId = $productId;
        $this->quantity = $quantity;
        $this->unitNetPrice = $unitNetPrice;
        $this->vatRate = $vatRate;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOrder(): SalesOrder
    {
        return $this->order;
    }

    public function getProductId(): int
    {
        return $this->productId;
    }

    public function getQuantity(): int
    {
        return $this->quantity;
    }

    public function getUnitNetPrice(): Money
    {
        return Money::ofMinor($this->unitNetPrice);
    }

    public function getVatRate(): VatRate
    {
        return $this->vatRate;
    }

    public function netValue(): Money
    {
        return $this->getUnitNetPrice()->timesQuantity($this->quantity);
    }
}
