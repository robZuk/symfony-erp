<?php

namespace App\Shared\Event;

/**
 * The goods of a sales order left the warehouse; the order can now be invoiced.
 */
final readonly class OrderShipped implements IntegrationEvent
{
    public function __construct(
        public int $orderId,
        public string $number,
        public int $customerId,
        /** Number of the goods issue (WZ); null for an order for services alone. */
        public ?string $goodsIssueNumber,
        public \DateTimeImmutable $occurredAt,
    ) {
    }

    public static function routingKey(): string
    {
        return 'sales.order.shipped';
    }
}
