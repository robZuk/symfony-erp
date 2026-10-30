<?php

namespace App\Inventory\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Query string of GET /api/stock: ?sku=KAB-HDMI-2M,LAD-65W.
 */
final class StockQuery
{
    public const int MAX_SKUS = 100;

    /** SKUs separated by commas. */
    #[Assert\NotBlank(message: 'Podaj co najmniej jeden indeks w parametrze sku.')]
    public string $sku = '';

    /**
     * @return list<string> distinct SKUs in upper case, in the order given
     */
    public function skus(): array
    {
        $skus = array_map(static fn (string $sku): string => mb_strtoupper(trim($sku)), explode(',', $this->sku));

        return array_values(array_unique(array_filter($skus, static fn (string $sku): bool => '' !== $sku)));
    }

    #[Assert\IsTrue(message: 'Najwyżej 100 indeksów w jednym zapytaniu.')]
    public function isNotTooLong(): bool
    {
        return \count($this->skus()) <= self::MAX_SKUS;
    }
}
