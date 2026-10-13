<?php

namespace App\Sales\Dto;

use App\Sales\Entity\SalesOrder;
use App\Shared\Tax\VatBreakdown;
use App\Shared\Tax\VatRate;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * What the order form edits. A SalesOrder is created or revised from it only once it is valid,
 * so the entity is never left half-filled.
 */
final class OrderDraft
{
    #[Assert\NotBlank(message: 'Wybierz odbiorcę.')]
    public ?int $customerId = null;

    #[Assert\NotBlank(message: 'Wybierz magazyn.')]
    public ?int $warehouseId = null;

    #[Assert\NotNull(message: 'Podaj datę zamówienia.')]
    public ?\DateTimeImmutable $orderDate;

    #[Assert\Length(max: 1000)]
    public ?string $note = null;

    /** @var array<int, OrderLineDraft> */
    #[Assert\Valid]
    #[Assert\Count(min: 1, minMessage: 'Zamówienie musi mieć przynajmniej jedną pozycję.')]
    public array $lines = [];

    public function __construct(\DateTimeImmutable $today)
    {
        $this->orderDate = $today->setTime(0, 0);
    }

    public static function fromOrder(SalesOrder $order): self
    {
        $draft = new self($order->getOrderDate());
        $draft->customerId = $order->getCustomerId();
        $draft->warehouseId = $order->getWarehouseId();
        $draft->note = $order->getNote();

        foreach ($order->getLines() as $line) {
            $lineDraft = new OrderLineDraft();
            $lineDraft->productId = $line->getProductId();
            $lineDraft->quantity = $line->getQuantity();
            $lineDraft->unitNetPrice = $line->getUnitNetPrice()->minor;
            $lineDraft->vatRate = $line->getVatRate();
            $draft->lines[] = $lineDraft;
        }

        return $draft;
    }

    /**
     * Totals of the lines that are complete enough to be priced.
     */
    public function totals(): VatBreakdown
    {
        $lines = [];
        foreach ($this->lines as $line) {
            $net = $line->netValue();
            if (null !== $net && null !== $line->vatRate) {
                $lines[] = [$net, $line->vatRate];
            }
        }

        return new VatBreakdown($lines);
    }

    /**
     * The lines in the shape SalesOrder::revise() takes. Call only on a valid draft.
     *
     * @return list<array{productId: int, quantity: int, unitNetPrice: int, vatRate: VatRate}>
     */
    public function completeLines(): array
    {
        $lines = [];
        foreach ($this->lines as $line) {
            if (null === $line->productId || null === $line->quantity || null === $line->unitNetPrice || null === $line->vatRate) {
                throw new \LogicException('The order draft has not been validated.');
            }

            $lines[] = [
                'productId' => $line->productId,
                'quantity' => $line->quantity,
                'unitNetPrice' => $line->unitNetPrice,
                'vatRate' => $line->vatRate,
            ];
        }

        return $lines;
    }
}
