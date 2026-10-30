<?php

namespace App\Sales\Repository;

use App\Sales\Entity\ApiOrderRequest;
use App\Sales\Entity\SalesOrder;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ApiOrderRequest>
 */
class ApiOrderRequestRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ApiOrderRequest::class);
    }

    public function findByKey(int $consumerId, string $idempotencyKey): ?ApiOrderRequest
    {
        return $this->findOneBy(['consumerId' => $consumerId, 'idempotencyKey' => $idempotencyKey]);
    }

    /**
     * The order, if this API client placed it; null otherwise, so another client's orders do not exist for it.
     */
    public function findOrderPlacedBy(int $consumerId, int $orderId): ?SalesOrder
    {
        return $this->findOneBy(['consumerId' => $consumerId, 'order' => $orderId])?->getOrder();
    }
}
