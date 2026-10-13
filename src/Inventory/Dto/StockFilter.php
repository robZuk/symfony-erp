<?php

namespace App\Inventory\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Query string of the stock list: /inventory/stock?q=...&warehouse=...&includeEmpty=1&page=...
 */
final class StockFilter
{
    #[Assert\Length(max: 100)]
    public string $q = '';

    #[Assert\Positive]
    public ?int $warehouse = null;

    /** Also show products whose quantity has dropped to zero. */
    public bool $includeEmpty = false;

    #[Assert\Positive]
    public int $page = 1;

    /**
     * Parameters to keep in pagination links, without the defaults.
     *
     * @return array<string, string|int>
     */
    public function toQuery(): array
    {
        return array_filter([
            'q' => $this->q,
            'warehouse' => $this->warehouse,
            'includeEmpty' => $this->includeEmpty ? 1 : null,
        ], static fn (string|int|null $value): bool => null !== $value && '' !== $value);
    }
}
