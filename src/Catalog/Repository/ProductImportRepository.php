<?php

namespace App\Catalog\Repository;

use App\Catalog\Entity\ProductImport;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ProductImport>
 */
class ProductImportRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProductImport::class);
    }

    /**
     * @return list<ProductImport>
     */
    public function latest(int $limit = 10): array
    {
        return $this->findBy([], ['id' => 'DESC'], $limit);
    }
}
