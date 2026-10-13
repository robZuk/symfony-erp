<?php

namespace App\Identity\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Query string of the user list: /admin/users?q=...&status=...&page=...
 */
final class UserFilter
{
    #[Assert\Length(max: 100)]
    public string $q = '';

    /** Everybody by default: a blocked account must stay in sight, or blocking looks like deleting. */
    #[Assert\Choice(choices: ['active', 'blocked', 'all'])]
    public string $status = 'all';

    #[Assert\Positive]
    public int $page = 1;

    /**
     * Parameters to keep in pagination links, without the defaults.
     *
     * @return array<string, string>
     */
    public function toQuery(): array
    {
        return array_filter(
            ['q' => $this->q, 'status' => 'all' === $this->status ? '' : $this->status],
            static fn (string $value): bool => '' !== $value,
        );
    }
}
