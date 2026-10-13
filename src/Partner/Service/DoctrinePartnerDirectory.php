<?php

namespace App\Partner\Service;

use App\Partner\Entity\Partner;
use App\Partner\Repository\PartnerRepository;
use App\Shared\Partner\PartnerDirectory;
use App\Shared\Partner\PartnerSummary;

final class DoctrinePartnerDirectory implements PartnerDirectory
{
    public function __construct(private readonly PartnerRepository $partners)
    {
    }

    public function find(int $id): ?PartnerSummary
    {
        $partner = $this->partners->find($id);

        return null === $partner ? null : self::summarize($partner);
    }

    public function findMany(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        $summaries = [];
        foreach ($this->partners->findBy(['id' => array_unique($ids)]) as $partner) {
            $summaries[(int) $partner->getId()] = self::summarize($partner);
        }

        return $summaries;
    }

    public function customers(): array
    {
        return array_map(
            self::summarize(...),
            $this->partners->findBy(['active' => true, 'customer' => true], ['name' => 'ASC']),
        );
    }

    public function suppliers(): array
    {
        return array_map(
            self::summarize(...),
            $this->partners->findBy(['active' => true, 'supplier' => true], ['name' => 'ASC']),
        );
    }

    private static function summarize(Partner $partner): PartnerSummary
    {
        return new PartnerSummary(
            (int) $partner->getId(),
            $partner->getName(),
            $partner->getNip(),
            $partner->getAddress()->getCity(),
        );
    }
}
