<?php

namespace App\Sales\Api;

use App\Sales\Entity\SalesOrder;
use App\Sales\Entity\SalesOrderLine;
use App\Shared\Api\Decimal;
use App\Shared\Catalog\ProductSummary;
use App\Shared\Inventory\WarehouseSummary;
use App\Shared\Partner\PartnerSummary;
use OpenApi\Attributes as OA;

/**
 * An order as the REST API shows it to the client that placed it.
 */
final readonly class OrderResource
{
    /**
     * @param list<OrderLineResource> $lines
     */
    public function __construct(
        #[OA\Property(example: 1042)]
        public int $id,
        #[OA\Property(description: 'Number given on confirmation', example: 'ZS/2026/10/0042')]
        public ?string $number,
        #[OA\Property(description: 'draft, confirmed (goods reserved), shipped, invoiced or cancelled', enum: ['draft', 'confirmed', 'shipped', 'invoiced', 'cancelled'])]
        public string $status,
        #[OA\Property(example: '5260250274')]
        public ?string $customerNip,
        #[OA\Property(example: 'MG')]
        public string $warehouse,
        public ?string $note,
        #[OA\Property(type: 'array', items: new OA\Items(type: OrderLineResource::class))]
        public array $lines,
        #[OA\Property(example: '69.80')]
        public string $netTotal,
        #[OA\Property(example: '16.05')]
        public string $vatTotal,
        #[OA\Property(example: '85.85')]
        public string $grossTotal,
        public \DateTimeImmutable $createdAt,
        #[OA\Property(description: 'When the goods left the warehouse')]
        public ?\DateTimeImmutable $shippedAt,
        #[OA\Property(example: 'FV/2026/10/0042')]
        public ?string $invoiceNumber,
    ) {
    }

    /**
     * @param array<int, ProductSummary> $products indexed by id, every product of the order
     */
    public static function of(SalesOrder $order, ?PartnerSummary $customer, ?WarehouseSummary $warehouse, array $products): self
    {
        $totals = $order->totals();

        return new self(
            (int) $order->getId(),
            $order->getNumber(),
            $order->getStatus()->value,
            $customer?->nip,
            $warehouse->code ?? '?',
            $order->getNote(),
            array_values(array_map(static fn (SalesOrderLine $line): OrderLineResource => new OrderLineResource(
                isset($products[$line->getProductId()]) ? $products[$line->getProductId()]->sku : '?',
                Decimal::quantity($line->getQuantity()),
                Decimal::money($line->getUnitNetPrice()->minor),
                $line->getVatRate()->value,
                Decimal::money($line->netValue()->minor),
            ), $order->getLines()->toArray())),
            Decimal::money($totals->net()->minor),
            Decimal::money($totals->vat()->minor),
            Decimal::money($totals->gross()->minor),
            $order->getCreatedAt(),
            $order->getShippedAt(),
            $order->getInvoiceNumber(),
        );
    }
}
