<?php

namespace App\Shared\Partner;

/**
 * Read-only access to business partners for other modules.
 */
interface PartnerDirectory
{
    public function find(int $id): ?PartnerSummary;

    /**
     * @param list<int> $ids
     *
     * @return array<int, PartnerSummary> indexed by partner id
     */
    public function findMany(array $ids): array;

    /**
     * @return list<PartnerSummary> active customers ordered by name
     */
    public function customers(): array;

    /**
     * @return list<PartnerSummary> active suppliers ordered by name
     */
    public function suppliers(): array;
}
