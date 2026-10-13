<?php

namespace App\Shared\Money;

use App\Shared\Tax\VatRate;

/**
 * An amount in złoty, kept as a whole number of grosze so that sums are always exact.
 * Rounding happens in one place only (roundedDivision) and is always "half away from zero",
 * the rule used on Polish invoices.
 */
final readonly class Money
{
    private function __construct(public int $minor)
    {
    }

    public static function ofMinor(int $grosze): self
    {
        return new self($grosze);
    }

    public static function zero(): self
    {
        return new self(0);
    }

    public function plus(self $other): self
    {
        return new self($this->minor + $other->minor);
    }

    public function minus(self $other): self
    {
        return new self($this->minor - $other->minor);
    }

    /**
     * Price of a quantity given in thousandths of a unit: 2,5 pcs at 10,00 zł is 25,00 zł.
     */
    public function timesQuantity(int $thousandths): self
    {
        return new self(self::roundedDivision($this->minor * $thousandths, 1000));
    }

    /**
     * VAT due on this net amount.
     */
    public function vatAt(VatRate $rate): self
    {
        return new self(self::roundedDivision($this->minor * $rate->basisPoints(), 10000));
    }

    public function isZero(): bool
    {
        return 0 === $this->minor;
    }

    public function equals(self $other): bool
    {
        return $this->minor === $other->minor;
    }

    /**
     * Integer division rounded to the nearest whole, halves away from zero, without floats.
     */
    private static function roundedDivision(int $dividend, int $divisor): int
    {
        $quotient = intdiv(abs($dividend) * 2 + $divisor, $divisor * 2);

        return $dividend < 0 ? -$quotient : $quotient;
    }
}
