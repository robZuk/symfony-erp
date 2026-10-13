<?php

namespace App\Shared\Validator;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

final class NipValidator extends ConstraintValidator
{
    private const array WEIGHTS = [6, 5, 7, 2, 3, 4, 5, 6, 7];

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof Nip) {
            throw new UnexpectedTypeException($constraint, Nip::class);
        }

        if (null === $value || '' === $value) {
            return;
        }

        if (!\is_string($value)) {
            throw new UnexpectedValueException($value, 'string');
        }

        if (!self::isValid($value)) {
            $this->context->buildViolation($constraint->message)->addViolation();
        }
    }

    /**
     * Strips the separators and the optional "PL" prefix people type in.
     */
    public static function normalize(string $nip): string
    {
        $nip = preg_replace('/[\s-]+/', '', mb_strtoupper($nip)) ?? '';

        return str_starts_with($nip, 'PL') ? substr($nip, 2) : $nip;
    }

    public static function isValid(string $nip): bool
    {
        $nip = self::normalize($nip);

        if (1 !== preg_match('/^\d{10}$/', $nip)) {
            return false;
        }

        $sum = 0;
        foreach (self::WEIGHTS as $position => $weight) {
            $sum += $weight * (int) $nip[$position];
        }

        // A remainder of 10 can never equal a single digit, so such numbers are never issued.
        return $sum % 11 === (int) $nip[9];
    }
}
