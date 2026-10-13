<?php

namespace App\Sales\Entity;

use App\Sales\Repository\SalesOrderRepository;
use App\Shared\Tax\VatBreakdown;
use App\Shared\Tax\VatRate;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A customer's order. Its status moves along the "sales_order" workflow; what each step
 * does to the warehouse is in App\Sales\Service\OrderLifecycle.
 */
#[ORM\Entity(repositoryClass: SalesOrderRepository::class)]
#[ORM\Table(name: 'sales_order')]
class SalesOrder
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Assigned on confirmation, e.g. ZS/2026/10/0001. */
    #[ORM\Column(length: 30, unique: true, nullable: true)]
    private ?string $number = null;

    #[ORM\Column(length: 10)]
    private OrderStatus $status = OrderStatus::Draft;

    /** An id from the Partner module. */
    #[ORM\Column]
    private int $customerId;

    /** The warehouse the goods will be shipped from; an id from the Inventory module. */
    #[ORM\Column]
    private int $warehouseId;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $orderDate;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $note = null;

    /** @var Collection<int, SalesOrderLine> */
    #[ORM\OneToMany(targetEntity: SalesOrderLine::class, mappedBy: 'order', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $lines;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /** The goods issue (WZ) posted when the order was shipped; ids from the Inventory module. */
    #[ORM\Column(nullable: true)]
    private ?int $shipmentDocumentId = null;

    #[ORM\Column(length: 30, nullable: true)]
    private ?string $shipmentNumber = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $shippedAt = null;

    /** The invoice issued for this order; ids from the Invoicing module. */
    #[ORM\Column(nullable: true)]
    private ?int $invoiceId = null;

    #[ORM\Column(length: 30, nullable: true)]
    private ?string $invoiceNumber = null;

    public function __construct(int $customerId, int $warehouseId, \DateTimeImmutable $orderDate, \DateTimeImmutable $now)
    {
        $this->customerId = $customerId;
        $this->warehouseId = $warehouseId;
        $this->orderDate = $orderDate->setTime(0, 0);
        $this->createdAt = $now;
        $this->lines = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNumber(): ?string
    {
        return $this->number;
    }

    /**
     * What to call the order on screen: its number, or a placeholder while it has none.
     */
    public function title(): string
    {
        return $this->number ?? \sprintf('ZS (szkic nr %d)', (int) $this->id);
    }

    public function getStatus(): OrderStatus
    {
        return $this->status;
    }

    /**
     * Called by the workflow only. Use OrderLifecycle to move an order between statuses.
     */
    public function setStatus(OrderStatus $status): void
    {
        $this->status = $status;
    }

    public function isDraft(): bool
    {
        return OrderStatus::Draft === $this->status;
    }

    public function getCustomerId(): int
    {
        return $this->customerId;
    }

    public function getWarehouseId(): int
    {
        return $this->warehouseId;
    }

    public function getOrderDate(): \DateTimeImmutable
    {
        return $this->orderDate;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    /**
     * @return Collection<int, SalesOrderLine>
     */
    public function getLines(): Collection
    {
        return $this->lines;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getShipmentDocumentId(): ?int
    {
        return $this->shipmentDocumentId;
    }

    public function getShipmentNumber(): ?string
    {
        return $this->shipmentNumber;
    }

    /**
     * Rewrites the whole order. Only a draft can be changed.
     *
     * @param list<array{productId: int, quantity: int, unitNetPrice: int, vatRate: VatRate}> $lines
     */
    public function revise(int $customerId, int $warehouseId, \DateTimeImmutable $orderDate, ?string $note, array $lines): void
    {
        if (!$this->isDraft()) {
            throw new \DomainException('Zmieniać można tylko zamówienie w statusie szkicu.');
        }

        $this->customerId = $customerId;
        $this->warehouseId = $warehouseId;
        $this->orderDate = $orderDate->setTime(0, 0);

        $note = trim((string) $note);
        $this->note = '' === $note ? null : $note;

        $this->lines->clear();
        foreach ($lines as $line) {
            $this->lines->add(new SalesOrderLine($this, $line['productId'], $line['quantity'], $line['unitNetPrice'], $line['vatRate']));
        }
    }

    public function assignNumber(string $number): void
    {
        if (null !== $this->number) {
            throw new \DomainException('Zamówienie ma już numer.');
        }

        $this->number = $number;
    }

    /**
     * @param array{int, string}|null $document id and number of the goods issue; null when nothing left the warehouse
     */
    public function recordShipment(\DateTimeImmutable $shippedAt, ?array $document): void
    {
        $this->shippedAt = $shippedAt;
        [$this->shipmentDocumentId, $this->shipmentNumber] = $document ?? [null, null];
    }

    public function getShippedAt(): ?\DateTimeImmutable
    {
        return $this->shippedAt;
    }

    public function recordInvoice(int $invoiceId, string $number): void
    {
        $this->invoiceId = $invoiceId;
        $this->invoiceNumber = $number;
    }

    public function getInvoiceId(): ?int
    {
        return $this->invoiceId;
    }

    public function getInvoiceNumber(): ?string
    {
        return $this->invoiceNumber;
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

    public function totals(): VatBreakdown
    {
        $lines = [];
        foreach ($this->lines as $line) {
            $lines[] = [$line->netValue(), $line->getVatRate()];
        }

        return VatBreakdown::ofLines($lines);
    }
}
