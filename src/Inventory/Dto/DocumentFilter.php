<?php

namespace App\Inventory\Dto;

use App\Inventory\Entity\DocumentStatus;
use App\Inventory\Entity\DocumentType;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Query string of the document list: /inventory/documents?q=...&type=...&status=...&warehouse=...&page=...
 */
final class DocumentFilter
{
    #[Assert\Length(max: 100)]
    public string $q = '';

    /** A DocumentType value, or empty for all types. */
    #[Assert\Choice(choices: ['', 'PZ', 'WZ', 'MM'])]
    public string $type = '';

    /** A DocumentStatus value, or empty for all statuses. */
    #[Assert\Choice(choices: ['', 'draft', 'posted'])]
    public string $status = '';

    #[Assert\Positive]
    public ?int $warehouse = null;

    #[Assert\Positive]
    public int $page = 1;

    public function type(): ?DocumentType
    {
        return DocumentType::tryFrom($this->type);
    }

    public function status(): ?DocumentStatus
    {
        return DocumentStatus::tryFrom($this->status);
    }

    /**
     * Parameters to keep in pagination links, without the defaults.
     *
     * @return array<string, string|int>
     */
    public function toQuery(): array
    {
        return array_filter([
            'q' => $this->q,
            'type' => $this->type,
            'status' => $this->status,
            'warehouse' => $this->warehouse,
        ], static fn (string|int|null $value): bool => null !== $value && '' !== $value);
    }
}
