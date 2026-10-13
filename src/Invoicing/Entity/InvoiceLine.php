<?php

namespace App\Invoicing\Entity;

use App\Shared\Money\Money;
use App\Shared\Tax\VatRate;
use Doctrine\ORM\Mapping as ORM;

/**
 * One line of an invoice. Everything is a copy: the product may change later, the line may not.
 *
 * On a correction the line describes a change: quantity is the difference (negative),
 * quantityBefore is what the customer had been invoiced for until then.
 */
#[ORM\Entity]
#[ORM\Table(name: 'invoicing_invoice_line')]
class InvoiceLine
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'lines')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Invoice $invoice;

    #[ORM\Column]
    private int $position;

    #[ORM\Column]
    private int $productId;

    #[ORM\Column(length: 40)]
    private string $sku;

    #[ORM\Column(length: 200)]
    private string $name;

    #[ORM\Column(length: 10)]
    private string $unit;

    /** In thousandths of the unit; negative on a correction. */
    #[ORM\Column]
    private int $quantity;

    /** In grosze. */
    #[ORM\Column]
    private int $unitNetPrice;

    #[ORM\Column(length: 5)]
    private VatRate $vatRate;

    /** In grosze; negative on a correction. */
    #[ORM\Column]
    private int $netValue;

    /** Correction only: the quantity invoiced so far, in thousandths. */
    #[ORM\Column(nullable: true)]
    private ?int $quantityBefore;

    /** Correction only: the line of the original invoice that is being changed. */
    #[ORM\ManyToOne]
    private ?InvoiceLine $correctedLine;

    public function __construct(
        Invoice $invoice,
        int $position,
        int $productId,
        string $sku,
        string $name,
        string $unit,
        int $quantity,
        Money $unitNetPrice,
        VatRate $vatRate,
        Money $netValue,
        ?int $quantityBefore = null,
        ?self $correctedLine = null,
    ) {
        $this->invoice = $invoice;
        $this->position = $position;
        $this->productId = $productId;
        $this->sku = $sku;
        $this->name = $name;
        $this->unit = $unit;
        $this->quantity = $quantity;
        $this->unitNetPrice = $unitNetPrice->minor;
        $this->vatRate = $vatRate;
        $this->netValue = $netValue->minor;
        $this->quantityBefore = $quantityBefore;
        $this->correctedLine = $correctedLine;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getInvoice(): Invoice
    {
        return $this->invoice;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function getProductId(): int
    {
        return $this->productId;
    }

    public function getSku(): string
    {
        return $this->sku;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getUnit(): string
    {
        return $this->unit;
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

    public function getNetValue(): Money
    {
        return Money::ofMinor($this->netValue);
    }

    public function getQuantityBefore(): ?int
    {
        return $this->quantityBefore;
    }

    /**
     * Correction only: the quantity the customer is invoiced for after this correction.
     */
    public function getQuantityAfter(): ?int
    {
        return null === $this->quantityBefore ? null : $this->quantityBefore + $this->quantity;
    }

    public function getCorrectedLine(): ?self
    {
        return $this->correctedLine;
    }
}
