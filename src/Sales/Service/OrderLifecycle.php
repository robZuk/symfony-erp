<?php

namespace App\Sales\Service;

use App\Sales\Entity\SalesOrder;
use App\Shared\Catalog\ProductDirectory;
use App\Shared\Inventory\InsufficientStock;
use App\Shared\Inventory\StockReservations;
use App\Shared\Numbering\DocumentNumberGenerator;
use App\Shared\Persistence\Transactional;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Workflow\WorkflowInterface;

/**
 * Moves an order from one status to the next and does what each step means for the warehouse:
 *
 *   confirm  draft -> confirmed      the order gets its number and its goods are reserved
 *   ship     confirmed -> shipped    the reservation becomes a posted goods issue (WZ)
 *   cancel   draft/confirmed -> cancelled   a reservation, if there was one, is given back
 *
 * Which steps are allowed from which status is defined by the "sales_order" workflow.
 * Every method is one transaction: the order and the warehouse change together or not at all.
 */
final class OrderLifecycle
{
    private const string NUMBER_SERIES = 'ZS';

    public function __construct(
        #[Target('sales_order')]
        private readonly WorkflowInterface $workflow,
        private readonly Transactional $transactional,
        private readonly EntityManagerInterface $entityManager,
        private readonly StockReservations $stock,
        private readonly ProductDirectory $products,
        private readonly DocumentNumberGenerator $numbers,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @throws InsufficientStock when the warehouse cannot cover the order
     * @throws \DomainException  when the order cannot be confirmed
     */
    public function confirm(SalesOrder $order): void
    {
        $this->transactional->run(function () use ($order): void {
            $this->lockAndRequire($order, 'confirm', 'Potwierdzić można tylko zamówienie w statusie szkicu.');

            if ($order->getLines()->isEmpty()) {
                throw new \DomainException('Zamówienie bez pozycji nie może zostać potwierdzone.');
            }

            $this->stock->reserve($order->getWarehouseId(), $this->stockQuantities($order));
            $order->assignNumber($this->numbers->next(self::NUMBER_SERIES, $order->getOrderDate()));
            $this->workflow->apply($order, 'confirm');
        });
    }

    /**
     * @throws \DomainException when the order cannot be shipped
     */
    public function ship(SalesOrder $order): void
    {
        $this->transactional->run(function () use ($order): void {
            $this->lockAndRequire($order, 'ship', 'Wydać można tylko potwierdzone zamówienie.');

            $quantities = $this->stockQuantities($order);
            $document = null;
            // An order for services alone has nothing to take out of the warehouse
            if ([] !== $quantities) {
                $shipment = $this->stock->ship(
                    $order->getWarehouseId(),
                    $order->getCustomerId(),
                    $quantities,
                    \sprintf('Zamówienie %s', $order->getNumber()),
                );
                $document = [$shipment->documentId, $shipment->number];
            }

            $order->recordShipment($this->clock->now(), $document);
            $this->workflow->apply($order, 'ship');
        });
    }

    /**
     * @throws \DomainException when the order cannot be cancelled any more
     */
    public function cancel(SalesOrder $order): void
    {
        $this->transactional->run(function () use ($order): void {
            $this->lockAndRequire($order, 'cancel', 'Anulować można tylko zamówienie, które nie zostało jeszcze wydane.');

            // Only a confirmed order holds a reservation
            if (!$order->isDraft()) {
                $this->stock->release($order->getWarehouseId(), $this->stockQuantities($order));
            }

            $this->workflow->apply($order, 'cancel');
        });
    }

    /**
     * Locks the order row and rereads it, so two people clicking at once cannot both get through.
     */
    private function lockAndRequire(SalesOrder $order, string $transition, string $refusal): void
    {
        $this->entityManager->refresh($order, LockMode::PESSIMISTIC_WRITE);

        if (!$this->workflow->can($order, $transition)) {
            throw new \DomainException($refusal);
        }
    }

    /**
     * Quantities of the products that are kept in stock; services are left out.
     *
     * @return array<int, int> product id => quantity in thousandths
     */
    private function stockQuantities(SalesOrder $order): array
    {
        $quantities = $order->quantitiesByProduct();
        $products = $this->products->findMany(array_keys($quantities));

        return array_filter(
            $quantities,
            static fn (int $productId): bool => isset($products[$productId]) && $products[$productId]->stockKept,
            \ARRAY_FILTER_USE_KEY,
        );
    }
}
