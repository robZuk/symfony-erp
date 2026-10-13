<?php

namespace App\Catalog\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Query string of the product list: /catalog/products?q=...&status=...&page=...
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
        ], static fn (string $value): bool => '' !== $value);
    }
}
