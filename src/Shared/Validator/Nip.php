<?php

namespace App\Shared\Validator;

use Symfony\Component\Validator\Constraint;

/**
 * Polish tax identification number: ten digits, the last one being a checksum.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD)]
final class Nip extends Constraint
{
    public string $message = 'Ten numer NIP jest nieprawidłowy.';
}
