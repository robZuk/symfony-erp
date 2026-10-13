<?php

namespace App\Shared\Tax;

/**
 * Polish VAT rates. The backed value is what gets stored and printed on documents.
 */
enum VatRate: string
{
    case Standard = '23';
    case Reduced8 = '8';
    case Reduced5 = '5';
    case Zero = '0';
    case Exempt = 'zw';

    /**
     * Rate in hundredths of a percent, so tax can be calculated on integers (2300 = 23%).
     */
    public function basisPoints(): int
    {
        return match ($this) {
            self::Standard => 2300,
            self::Reduced8 => 800,
            self::Reduced5 => 500,
            self::Zero, self::Exempt => 0,
        };
    }

    public function label(): string
    {
        return self::Exempt === $this ? 'zw.' : $this->value.'%';
    }
}
