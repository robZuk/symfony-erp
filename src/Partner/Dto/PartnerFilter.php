<?php

namespace App\Partner\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Query string of the partner list: /partners?q=...&role=...&status=...&page=...
 */
final class PartnerFilter
{
    public const string ROLE_ALL = 'all';
    public const string ROLE_CUSTOMER = 'customer';
    public const string ROLE_SUPPLIER = 'supplier';

    public const string STATUS_ACTIVE = 'active';
    public const string STATUS_INACTIVE = 'inactive';
    public const string STATUS_ALL = 'all';

    #[Assert\Length(max: 100)]
    public string $q = '';

    #[Assert\Choice(choices: [self::ROLE_ALL, self::ROLE_CUSTOMER, self::ROLE_SUPPLIER])]
    public string $role = self::ROLE_ALL;

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
            'role' => self::ROLE_ALL === $this->role ? '' : $this->role,
            'status' => self::STATUS_ACTIVE === $this->status ? '' : $this->status,
        ], static fn (string $value): bool => '' !== $value);
    }
}
