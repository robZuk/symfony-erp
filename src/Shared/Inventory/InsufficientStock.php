<?php

namespace App\Shared\Inventory;

/**
 * Thrown when a document would take more out of a warehouse than is available there.
 */
final class InsufficientStock extends \DomainException
{
    /**
     * @param array<int, array{requested: int, available: int}> $shortages indexed by product id, in thousandths
     */
    public function __construct(public readonly array $shortages)
    {
        parent::__construct('Brak wystarczającej ilości towaru w magazynie.');
    }
}
