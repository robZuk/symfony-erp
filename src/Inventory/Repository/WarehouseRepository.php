<?php

namespace App\Inventory\Repository;

use App\Inventory\Entity\Warehouse;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Warehouse>
 */
class WarehouseRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Warehouse::class);
    }

    public function save(Warehouse $warehouse): void
    {
        $this->getEntityManager()->persist($warehouse);
        $this->getEntityManager()->flush();
    }

    /**
     * @return list<Warehouse>
     */
    public function findAllOrdered(): array
    {
        return $this->findBy([], ['code' => 'ASC']);
    }
}
