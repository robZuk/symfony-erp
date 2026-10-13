<?php

namespace App\Shared\Twig;

use App\Shared\Form\QuantityType;
use Twig\Attribute\AsTwigFilter;

final class QuantityExtension
{
    /**
     * Prints a quantity stored in thousandths: 2500 becomes "2,5", 3000 becomes "3".
     */
    #[AsTwigFilter('quantity')]
    public function format(int $thousandths): string
    {
        $formatter = new \NumberFormatter('pl_PL', \NumberFormatter::DECIMAL);
        $formatter->setAttribute(\NumberFormatter::MIN_FRACTION_DIGITS, 0);
        $formatter->setAttribute(\NumberFormatter::MAX_FRACTION_DIGITS, 3);

        return (string) $formatter->format($thousandths / QuantityType::SCALE);
    }
}
