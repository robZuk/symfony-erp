<?php

namespace App\Sales\Dto;

use App\Sales\Entity\OrderStatus;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Query string of the order list: /sales/orders?q=...&status=...&page=...
 */
final class OrderFilter
{
    #[Assert\Length(max: 100)]
    public string $q = '';

    /** An OrderStatus value, or empty for all statuses. */
    #[Assert\Choice(choices: ['', 'draft', 'confirmed', 'shipped', 'invoiced', 'cancelled'])]
    public string $status = '';

    #[Assert\Positive]
    public int $page = 1;

    public function status(): ?OrderStatus
    {
        return OrderStatus::tryFrom($this->status);
    }

    /**
     * Parameters to keep in pagination links, without the defaults.
     *
     * @return array<string, string>
     */
    public function toQuery(): array
    {
        return array_filter(['q' => $this->q, 'status' => $this->status], static fn (string $value): bool => '' !== $value);
    }
}
