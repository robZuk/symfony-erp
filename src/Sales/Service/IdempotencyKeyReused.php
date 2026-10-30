<?php

namespace App\Sales\Service;

/**
 * The Idempotency-Key was already used for a request with a different body. Treating it as a replay
 * would hand the client an order it did not ask for, and placing it would defeat the key's purpose.
 */
final class IdempotencyKeyReused extends \DomainException
{
    public function __construct()
    {
        parent::__construct('Ten klucz Idempotency-Key został już użyty dla innego zamówienia. Dla nowego zamówienia wygeneruj nowy klucz.');
    }
}
