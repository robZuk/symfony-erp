<?php

namespace App\Identity\Repository;

use App\Identity\Entity\ApiKey;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ApiKey>
 */
class ApiKeyRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ApiKey::class);
    }

    public function save(ApiKey $key): void
    {
        $this->getEntityManager()->persist($key);
        $this->getEntityManager()->flush();
    }

    /**
     * The key this secret belongs to, unless it was revoked.
     */
    public function findUsable(string $secret): ?ApiKey
    {
        $key = $this->findOneBy(['tokenHash' => ApiKey::hash($secret)]);

        return null === $key || $key->isRevoked() ? null : $key;
    }

    /**
     * @return list<ApiKey> newest first
     */
    public function newestFirst(): array
    {
        return $this->findBy([], ['id' => 'DESC']);
    }
}
