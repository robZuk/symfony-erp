<?php

namespace App\Shared\Api;

/**
 * Amounts and quantities in the REST API are decimal strings ("49.90", "2.5"), not JSON numbers:
 * a client reading them as floating point would lose the grosze. Inside the application they are
 * whole grosze and thousandths of a unit, as everywhere else.
 */
final class Decimal
{
    /**
     * 4990 grosze => "49.90".
     */
    public static function money(int $grosze): string
    {
        return \sprintf('%s%d.%02d', $grosze < 0 ? '-' : '', intdiv(abs($grosze), 100), abs($grosze) % 100);
    }

    /**
     * 2500 thousandths => "2.5", 3000 => "3".
     */
    public static function quantity(int $thousandths): string
    {
        $text = \sprintf('%s%d.%03d', $thousandths < 0 ? '-' : '', intdiv(abs($thousandths), 1000), abs($thousandths) % 1000);

        return rtrim(rtrim($text, '0'), '.');
    }

    /**
     * "2.5" => 2500 thousandths; null when the text is not a positive quantity with at most three decimals.
     */
    public static function parseQuantity(string $text): ?int
    {
        if (1 !== preg_match('/^(\d{1,9})(?:\.(\d{1,3}))?$/', $text, $parts)) {
            return null;
        }

        $thousandths = (int) $parts[1] * 1000 + (int) str_pad($parts[2] ?? '', 3, '0');

        return $thousandths > 0 ? $thousandths : null;
    }
}
