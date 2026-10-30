<?php

namespace App\Sales\Service;

/**
 * The order names a customer, a warehouse or products the ERP does not know or does not sell.
 */
final class UnresolvedReferences extends \DomainException
{
    /**
     * @param list<array{field: string, message: string}> $problems one per unknown reference, like validation errors
     */
    public function __construct(public readonly array $problems)
    {
        parent::__construct('Zamówienie wskazuje odbiorcę, magazyn albo produkty, których nie ma w systemie.');
    }
}
