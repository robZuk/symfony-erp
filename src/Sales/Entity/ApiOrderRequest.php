<?php

namespace App\Sales\Entity;

use App\Sales\Repository\ApiOrderRequestRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * An order placed through the REST API, remembered under the client's Idempotency-Key.
 *
 * A client that sends the same request again (its connection dropped before the answer came) gets
 * the order it already placed instead of a second one with a second reservation. The unique index
 * enforces this in the database itself.
 *
 * It also says which API client placed which order: a client sees its own orders only.
 */
#[ORM\Entity(repositoryClass: ApiOrderRequestRepository::class)]
#[ORM\Table(name: 'sales_api_order_request')]
#[ORM\UniqueConstraint(name: 'sales_api_order_request_key', columns: ['consumer_id', 'idempotency_key'])]
#[ORM\UniqueConstraint(name: 'sales_api_order_request_order', columns: ['order_id'])]
class ApiOrderRequest
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    public function __construct(
        /** The API key that placed the order (ApiConsumer::apiConsumerId()). */
        #[ORM\Column]
        private int $consumerId,
        #[ORM\Column(length: 100)]
        private string $idempotencyKey,
        /** SHA-256 of the request body: the same key with a different body is an error, not a replay. */
        #[ORM\Column(length: 64)]
        private string $requestHash,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false)]
        private SalesOrder $order,
        #[ORM\Column]
        private \DateTimeImmutable $createdAt,
    ) {
    }

    public function getOrder(): SalesOrder
    {
        return $this->order;
    }

    public function isSameRequest(string $requestHash): bool
    {
        return hash_equals($this->requestHash, $requestHash);
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
