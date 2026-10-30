<?php

namespace App\Sales\Service;

use App\Sales\Dto\PlaceOrderLine;
use App\Sales\Dto\PlaceOrderRequest;
use App\Sales\Entity\ApiOrderRequest;
use App\Sales\Entity\SalesOrder;
use App\Sales\Repository\ApiOrderRequestRepository;
use App\Shared\Api\ApiConsumer;
use App\Shared\Audit\ActivityLog;
use App\Shared\Catalog\ProductDirectory;
use App\Shared\Inventory\InsufficientStock;
use App\Shared\Inventory\WarehouseDirectory;
use App\Shared\Partner\PartnerDirectory;
use App\Shared\Persistence\Transactional;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Lock\LockFactory;

/**
 * Places an order sent by another system through the REST API: a confirmed order, with its goods
 * reserved, exactly as if a salesperson had entered and confirmed it.
 *
 * The client sends an Idempotency-Key with every order. Sending the same request again with the same
 * key returns the order already placed; nothing is reserved twice. Concurrent requests with one key
 * are serialized by a lock, and the database's unique index backs that up.
 */
final class OrderPlacement
{
    public function __construct(
        private readonly Transactional $transactional,
        private readonly EntityManagerInterface $entityManager,
        private readonly ApiOrderRequestRepository $requests,
        private readonly OrderLifecycle $lifecycle,
        private readonly PartnerDirectory $partners,
        private readonly WarehouseDirectory $warehouses,
        private readonly ProductDirectory $products,
        private readonly ActivityLog $activity,
        private readonly LockFactory $locks,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @param string $requestHash SHA-256 of the request body, to tell a replay from a different request under the same key
     *
     * @throws IdempotencyKeyReused the key was used for a different request
     * @throws UnresolvedReferences unknown customer, warehouse or products
     * @throws InsufficientStock    the warehouse cannot cover the order; nothing is saved
     */
    public function place(ApiConsumer $client, string $idempotencyKey, string $requestHash, PlaceOrderRequest $request): PlacedOrder
    {
        $lock = $this->locks->createLock(\sprintf('api-order-%d-%s', $client->apiConsumerId(), hash('sha256', $idempotencyKey)));
        $lock->acquire(true);

        try {
            $earlier = $this->requests->findByKey($client->apiConsumerId(), $idempotencyKey);
            if (null !== $earlier) {
                if (!$earlier->isSameRequest($requestHash)) {
                    throw new IdempotencyKeyReused();
                }

                return new PlacedOrder($earlier->getOrder(), replayed: true);
            }

            return $this->transactional->run(function () use ($client, $idempotencyKey, $requestHash, $request): PlacedOrder {
                $now = $this->clock->now();
                [$customerId, $warehouseId, $lines] = $this->resolve($request);

                $order = new SalesOrder($customerId, $warehouseId, $now, $now);
                $order->revise($customerId, $warehouseId, $now, $request->note, $lines);
                $this->entityManager->persist($order);
                $this->entityManager->flush();
                $this->activity->record('sales.order.drafted', \sprintf('Utworzono zamówienie %s przez API (%s).', $order->title(), $client->apiConsumerName()), 'sales_order_show', ['id' => (int) $order->getId()]);

                // Reserves the goods and numbers the order, or refuses and so undoes everything above
                $this->lifecycle->confirm($order);

                $this->entityManager->persist(new ApiOrderRequest($client->apiConsumerId(), $idempotencyKey, $requestHash, $order, $now));

                return new PlacedOrder($order, replayed: false);
            });
        } finally {
            $lock->release();
        }
    }

    /**
     * @return array{int, int, list<array{productId: int, quantity: int, unitNetPrice: int, vatRate: \App\Shared\Tax\VatRate}>}
     *
     * @throws UnresolvedReferences listing everything that is unknown at once, not just the first
     */
    private function resolve(PlaceOrderRequest $request): array
    {
        $problems = [];

        $customer = $this->partners->findCustomerByNip($request->customerNip);
        if (null === $customer) {
            $problems[] = ['field' => 'customerNip', 'message' => \sprintf('Nie ma aktywnego odbiorcy o NIP %s.', $request->customerNip)];
        }

        $warehouse = $this->warehouses->findByCode($request->warehouse);
        if (null === $warehouse) {
            $problems[] = ['field' => 'warehouse', 'message' => \sprintf('Nie ma aktywnego magazynu %s.', $request->warehouse)];
        }

        $products = $this->products->findBySkus(array_map(static fn (PlaceOrderLine $line): string => $line->sku, $request->lines));
        $lines = [];
        foreach ($request->lines as $index => $line) {
            $product = $products[mb_strtoupper(trim($line->sku))] ?? null;
            if (null === $product || !$product->active) {
                $problems[] = ['field' => \sprintf('lines[%d].sku', $index), 'message' => \sprintf('Nie ma w sprzedaży produktu %s.', $line->sku)];

                continue;
            }

            // The catalog decides the price, not the client
            $lines[] = ['productId' => $product->id, 'quantity' => $line->thousandths(), 'unitNetPrice' => $product->netPrice, 'vatRate' => $product->vatRate];
        }

        if ([] !== $problems || null === $customer || null === $warehouse) {
            throw new UnresolvedReferences($problems);
        }

        return [$customer->id, $warehouse->id, $lines];
    }
}
