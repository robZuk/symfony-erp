<?php

namespace App\Shared\Twig;

use App\Shared\Money\Money;
use Twig\Attribute\AsTwigFilter;

final class MoneyExtension
{
    /**
     * Prints an amount as "1 234,50 zł". Accepts a Money object or a number of grosze.
     */
    #[AsTwigFilter('money')]
    public function format(Money|int $amount): string
    {
        $formatter = new \NumberFormatter('pl_PL', \NumberFormatter::CURRENCY);

        return (string) $formatter->formatCurrency(($amount instanceof Money ? $amount->minor : $amount) / 100, 'PLN');
    }
}
