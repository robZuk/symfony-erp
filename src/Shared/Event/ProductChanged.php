<?php

namespace App\Shared\Event;

/**
 * A product was added or its catalog data changed (name, price, VAT rate, withdrawal...).
 *
 * The event carries no product data on purpose: a subscriber reads the current state, so events
 * that arrive late, out of order or twice still leave it with the latest version.
 */
final readonly class ProductChanged implements IntegrationEvent
{
    public function __construct(
        public int $productId,
        public string $sku,
        public \DateTimeImmutable $occurredAt,
    ) {
    }

    public static function routingKey(): string
    {
        return 'catalog.product.changed';
    }
}
