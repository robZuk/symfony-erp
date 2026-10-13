<?php

namespace App\Sales\Service;

use App\Sales\Entity\SalesOrderLine;
use App\Sales\Repository\SalesOrderRepository;
use App\Shared\Sales\BillableLine;
use App\Shared\Sales\BillableOrder;
use App\Shared\Sales\BillableOrders;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Workflow\WorkflowInterface;

final class DoctrineBillableOrders implements BillableOrders
{
    public function __construct(
        private readonly SalesOrderRepository $orders,
        private readonly EntityManagerInterface $entityManager,
        #[Target('sales_order')]
        private readonly WorkflowInterface $workflow,
    ) {
    }

    public function find(int $orderId): ?BillableOrder
    {
        $order = $this->orders->find($orderId);
        if (null === $order) {
            return null;
        }

        return new BillableOrder(
            $orderId,
            $order->getNumber(),
            $order->getCustomerId(),
            $this->workflow->can($order, 'invoice'),
            $order->getShippedAt(),
            array_values(array_map(
                static fn (SalesOrderLine $line): BillableLine => new BillableLine(
                    $line->getProductId(),
                    $line->getQuantity(),
                    $line->getUnitNetPrice(),
                    $line->getVatRate(),
                ),
                $order->getLines()->toArray(),
            )),
        );
    }

    public function markInvoiced(int $orderId, int $invoiceId, string $invoiceNumber): void
    {
        if (!$this->entityManager->getConnection()->isTransactionActive()) {
            throw new \LogicException('An order must be marked as invoiced in the transaction that saves the invoice.');
        }

        $order = $this->orders->find($orderId) ?? throw new \DomainException('Zamówienie nie istnieje.');

        // Lock the row and reread it, so the same order cannot be invoiced twice at the same moment
        $this->entityManager->refresh($order, LockMode::PESSIMISTIC_WRITE);
        if (!$this->workflow->can($order, 'invoice')) {
            throw new \DomainException('Fakturę można wystawić tylko do wydanego, niezafakturowanego zamówienia.');
        }

        $order->recordInvoice($invoiceId, $invoiceNumber);
        $this->workflow->apply($order, 'invoice');
    }
}
