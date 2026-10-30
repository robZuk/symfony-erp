<?php

namespace App\Shared\Event;

/**
 * A sales order was cancelled before shipping; a reservation it held was given back.
 */
final readonly class OrderCancelled implements IntegrationEvent
{
    public function __construct(
        public int $orderId,
        /** Null for a draft, which never got a number. */
        public ?string $number,
        public int $customerId,
        public \DateTimeImmutable $occurredAt,
    ) {
    }

    public static function routingKey(): string
    {
        return 'sales.order.cancelled';
    }
}
