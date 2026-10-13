<?php

namespace App\Invoicing\Dto;

use App\Invoicing\Entity\InvoiceType;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Query string of the invoice list: /invoicing/invoices?q=...&type=...&page=...
 */
final class InvoiceFilter
{
    #[Assert\Length(max: 100)]
    public string $q = '';

    /** An InvoiceType value, or empty for all. */
    #[Assert\Choice(choices: ['', 'invoice', 'correction'])]
    public string $type = '';

    #[Assert\Positive]
    public int $page = 1;

    public function type(): ?InvoiceType
    {
        return InvoiceType::tryFrom($this->type);
    }

    /**
     * Parameters to keep in pagination links, without the defaults.
     *
     * @return array<string, string>
     */
    public function toQuery(): array
    {
        return array_filter(['q' => $this->q, 'type' => $this->type], static fn (string $value): bool => '' !== $value);
    }
}
