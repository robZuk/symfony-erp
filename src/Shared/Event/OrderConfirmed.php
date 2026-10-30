<?php

namespace App\Shared\Event;

/**
 * A sales order got its number and its goods are reserved in the warehouse.
 */
final readonly class OrderConfirmed implements IntegrationEvent
{
    public function __construct(
        public int $orderId,
        public string $number,
        public int $customerId,
        public int $warehouseId,
        public \DateTimeImmutable $occurredAt,
    ) {
    }

    public static function routingKey(): string
    {
        return 'sales.order.confirmed';
    }
}
