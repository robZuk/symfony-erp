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
     * An active customer with this NIP (digits only, separators are ignored), or null.
     */
    public function findCustomerByNip(string $nip): ?PartnerSummary;

    /**
     * @return list<PartnerSummary> active customers ordered by name
     */
    public function customers(): array;

    /**
     * @return list<PartnerSummary> active suppliers ordered by name
     */
    public function suppliers(): array;
}
