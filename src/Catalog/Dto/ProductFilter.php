<?php

namespace App\Catalog\Dto;

use App\Catalog\Entity\ProductKind;
use App\Shared\Tax\VatRate;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Query string of the product list: /catalog/products?q=...&status=...&kind=...&vat=...&page=...
 */
final class ProductFilter
{
    public const string STATUS_ACTIVE = 'active';
    public const string STATUS_INACTIVE = 'inactive';
    public const string STATUS_ALL = 'all';

    #[Assert\Length(max: 100)]
    public string $q = '';

    #[Assert\Choice(choices: [self::STATUS_ACTIVE, self::STATUS_INACTIVE, self::STATUS_ALL])]
    public string $status = self::STATUS_ACTIVE;

    /** A ProductKind value, or empty for every kind. */
    #[Assert\Choice(callback: [self::class, 'kinds'])]
    public string $kind = '';

    /** A VatRate value, or empty for every rate. */
    #[Assert\Choice(callback: [self::class, 'vatRates'])]
    public string $vat = '';

    #[Assert\Positive]
    public int $page = 1;

    /**
     * Parameters to keep in pagination links, without the defaults.
     *
     * @return array<string, string>
     */
    public function toQuery(): array
    {
        return array_filter([
            'q' => $this->q,
            'status' => self::STATUS_ACTIVE === $this->status ? '' : $this->status,
            'kind' => $this->kind,
            'vat' => $this->vat,
        ], static fn (string $value): bool => '' !== $value);
    }

    /**
     * Words to search for; an empty string means the plain list.
     */
    public function searchTerm(): string
    {
        return trim($this->q);
    }

    /**
     * @return list<string>
     */
    public static function kinds(): array
    {
        return ['', ...array_map(static fn (ProductKind $kind): string => $kind->value, ProductKind::cases())];
    }

    /**
     * @return list<string>
     */
    public static function vatRates(): array
    {
        return ['', ...array_map(static fn (VatRate $rate): string => $rate->value, VatRate::cases())];
    }
}
