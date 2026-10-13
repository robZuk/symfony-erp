<?php

namespace App\Invoicing\Entity;

use App\Invoicing\Repository\InvoiceRepository;
use App\Shared\Money\Money;
use App\Shared\Tax\VatBreakdown;
use App\Shared\Tax\VatBreakdownRow;
use App\Shared\Tax\VatRate;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A VAT invoice or a correcting invoice. It is complete and final from the moment it is created:
 * there are no setters for its contents, and mistakes are fixed by issuing a correction.
 * Only the state of its PDF file changes afterwards.
 */
#[ORM\Entity(repositoryClass: InvoiceRepository::class)]
#[ORM\Table(name: 'invoicing_invoice')]
class Invoice
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 12)]
    private InvoiceType $type;

    #[ORM\Column(length: 30, unique: true)]
    private string $number;

    /** The sales order this invoice bills; an id from the Sales module. */
    #[ORM\Column]
    private int $orderId;

    #[ORM\Column(length: 30, nullable: true)]
    private ?string $orderNumber;

    /** Correction only: the invoice being corrected. */
    #[ORM\ManyToOne(inversedBy: 'corrections')]
    private ?Invoice $corrected;

    /** @var Collection<int, Invoice> */
    #[ORM\OneToMany(targetEntity: self::class, mappedBy: 'corrected')]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $corrections;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $correctionReason;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $issueDate;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $saleDate;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $dueDate;

    #[ORM\Column(length: 10)]
    private PaymentMethod $paymentMethod;

    #[ORM\Embedded(class: Party::class, columnPrefix: 'seller_')]
    private Party $seller;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $sellerBankAccount;

    #[ORM\Embedded(class: Party::class, columnPrefix: 'buyer_')]
    private Party $buyer;

    /** @var Collection<int, InvoiceLine> */
    #[ORM\OneToMany(targetEntity: InvoiceLine::class, mappedBy: 'invoice', cascade: ['persist'])]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $lines;

    /**
     * Net and VAT per rate in grosze, fixed when the invoice is issued.
     *
     * @var list<array{rate: string, net: int, vat: int}>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $vatRows = [];

    /** Totals in grosze, stored so that lists and reports do not have to add up rows. */
    #[ORM\Column]
    private int $netTotal = 0;

    #[ORM\Column]
    private int $vatTotal = 0;

    #[ORM\Column]
    private int $grossTotal = 0;

    #[ORM\Column(length: 10)]
    private PdfStatus $pdfStatus = PdfStatus::Pending;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        InvoiceType $type,
        string $number,
        int $orderId,
        ?string $orderNumber,
        Party $seller,
        ?string $sellerBankAccount,
        Party $buyer,
        \DateTimeImmutable $issueDate,
        \DateTimeImmutable $saleDate,
        \DateTimeImmutable $dueDate,
        PaymentMethod $paymentMethod,
        \DateTimeImmutable $now,
        ?self $corrected = null,
        ?string $correctionReason = null,
    ) {
        if ((InvoiceType::Correction === $type) !== (null !== $corrected)) {
            throw new \LogicException('A correction, and only a correction, refers to the invoice it corrects.');
        }

        $this->type = $type;
        $this->number = $number;
        $this->orderId = $orderId;
        $this->orderNumber = $orderNumber;
        $this->seller = $seller;
        $this->sellerBankAccount = $sellerBankAccount;
        $this->buyer = $buyer;
        $this->issueDate = $issueDate->setTime(0, 0);
        $this->saleDate = $saleDate->setTime(0, 0);
        $this->dueDate = $dueDate->setTime(0, 0);
        $this->paymentMethod = $paymentMethod;
        $this->createdAt = $now;
        $this->corrected = $corrected;
        $this->correctionReason = $correctionReason;
        $this->lines = new ArrayCollection();
        $this->corrections = new ArrayCollection();

        $corrected?->corrections->add($this);
    }

    /**
     * Used only while the invoice is being issued; the totals are set afterwards with close().
     */
    public function addLine(
        int $productId,
        string $sku,
        string $name,
        string $unit,
        int $quantity,
        Money $unitNetPrice,
        VatRate $vatRate,
        Money $netValue,
        ?int $quantityBefore = null,
        ?InvoiceLine $correctedLine = null,
    ): void {
        $this->assertNotIssued();

        $this->lines->add(new InvoiceLine(
            $this,
            $this->lines->count() + 1,
            $productId,
            $sku,
            $name,
            $unit,
            $quantity,
            $unitNetPrice,
            $vatRate,
            $netValue,
            $quantityBefore,
            $correctedLine,
        ));
    }

    /**
     * Fixes the totals of the invoice. They are stored, never recalculated, so the document
     * keeps saying exactly what it said on the day it was issued.
     */
    public function close(VatBreakdown $totals): void
    {
        $this->assertNotIssued();

        $this->vatRows = array_map(
            static fn (VatBreakdownRow $row): array => ['rate' => $row->rate->value, 'net' => $row->net->minor, 'vat' => $row->vat->minor],
            $totals->rows,
        );
        $this->netTotal = $totals->net()->minor;
        $this->vatTotal = $totals->vat()->minor;
        $this->grossTotal = $totals->gross()->minor;
    }

    /**
     * Net values of the lines as they stand, each paired with its VAT rate.
     *
     * @return list<array{Money, VatRate}>
     */
    public function lineValues(): array
    {
        $values = [];
        foreach ($this->lines as $line) {
            $values[] = [$line->getNetValue(), $line->getVatRate()];
        }

        return $values;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getType(): InvoiceType
    {
        return $this->type;
    }

    public function isCorrection(): bool
    {
        return InvoiceType::Correction === $this->type;
    }

    public function getNumber(): string
    {
        return $this->number;
    }

    public function getOrderId(): int
    {
        return $this->orderId;
    }

    public function getOrderNumber(): ?string
    {
        return $this->orderNumber;
    }

    public function getCorrected(): ?self
    {
        return $this->corrected;
    }

    /**
     * @return Collection<int, Invoice>
     */
    public function getCorrections(): Collection
    {
        return $this->corrections;
    }

    public function getCorrectionReason(): ?string
    {
        return $this->correctionReason;
    }

    public function getIssueDate(): \DateTimeImmutable
    {
        return $this->issueDate;
    }

    public function getSaleDate(): \DateTimeImmutable
    {
        return $this->saleDate;
    }

    public function getDueDate(): \DateTimeImmutable
    {
        return $this->dueDate;
    }

    public function getPaymentMethod(): PaymentMethod
    {
        return $this->paymentMethod;
    }

    public function getSeller(): Party
    {
        return $this->seller;
    }

    public function getSellerBankAccount(): ?string
    {
        return $this->sellerBankAccount;
    }

    public function getBuyer(): Party
    {
        return $this->buyer;
    }

    /**
     * @return Collection<int, InvoiceLine>
     */
    public function getLines(): Collection
    {
        return $this->lines;
    }

    public function getNetTotal(): Money
    {
        return Money::ofMinor($this->netTotal);
    }

    public function getVatTotal(): Money
    {
        return Money::ofMinor($this->vatTotal);
    }

    public function getGrossTotal(): Money
    {
        return Money::ofMinor($this->grossTotal);
    }

    /**
     * Net, VAT and gross split by VAT rate, as printed at the bottom of the invoice.
     */
    public function totals(): VatBreakdown
    {
        return VatBreakdown::ofRows(array_map(
            static fn (array $row): VatBreakdownRow => new VatBreakdownRow(VatRate::from($row['rate']), Money::ofMinor($row['net']), Money::ofMinor($row['vat'])),
            $this->vatRows,
        ));
    }

    /**
     * What the customer is invoiced for on a line of this invoice today,
     * after every correction issued so far. In thousandths of the unit.
     */
    public function currentQuantity(InvoiceLine $line): int
    {
        $quantity = $line->getQuantity();
        foreach ($this->corrections as $correction) {
            foreach ($correction->getLines() as $correctionLine) {
                if ($correctionLine->getCorrectedLine() === $line) {
                    $quantity += $correctionLine->getQuantity();
                }
            }
        }

        return $quantity;
    }

    /**
     * Gross amount due after every correction issued so far.
     */
    public function grossAfterCorrections(): Money
    {
        $gross = $this->getGrossTotal();
        foreach ($this->corrections as $correction) {
            $gross = $gross->plus($correction->getGrossTotal());
        }

        return $gross;
    }

    public function getPdfStatus(): PdfStatus
    {
        return $this->pdfStatus;
    }

    public function setPdfStatus(PdfStatus $pdfStatus): void
    {
        $this->pdfStatus = $pdfStatus;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    private function assertNotIssued(): void
    {
        if (null !== $this->id) {
            throw new \DomainException('Wystawionej faktury nie można zmieniać.');
        }
    }
}
