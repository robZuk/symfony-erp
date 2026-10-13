<?php

namespace App\Sales\Twig;

use App\Sales\Dto\OrderDraft;
use App\Sales\Entity\SalesOrder;
use App\Sales\Form\SalesOrderType;
use App\Sales\Repository\SalesOrderRepository;
use App\Shared\Tax\VatBreakdown;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Symfony\UX\LiveComponent\LiveCollectionTrait;

/**
 * The order form. It re-renders on the server whenever a field changes, which is how lines are
 * added and removed, prices are filled in from the catalog and the totals stay up to date,
 * all without hand-written JavaScript.
 */
#[AsLiveComponent(name: 'Sales:OrderEditor', template: 'sales/order/_editor.html.twig')]
#[IsGranted('ROLE_SALES_EDIT')]
final class OrderEditor extends AbstractController
{
    use DefaultActionTrait;
    use LiveCollectionTrait;

    /** The draft being edited, or null for a new order. */
    #[LiveProp]
    public ?int $orderId = null;

    public function __construct(
        private readonly SalesOrderRepository $orders,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * Totals of what is in the form right now.
     */
    public function getTotals(): VatBreakdown
    {
        $draft = $this->getForm()->getData();

        return $draft instanceof OrderDraft ? $draft->totals() : VatBreakdown::ofLines([]);
    }

    #[LiveAction]
    public function save(): Response
    {
        // Validates the whole form; if it is invalid the component re-renders with the errors
        $this->submitForm();

        $draft = $this->getForm()->getData();
        if (!$draft instanceof OrderDraft || null === $draft->customerId || null === $draft->warehouseId || null === $draft->orderDate) {
            throw new \LogicException('A valid order form always has a customer, a warehouse and a date.');
        }

        $now = $this->clock->now();
        $order = $this->existingOrder() ?? new SalesOrder($draft->customerId, $draft->warehouseId, $draft->orderDate, $now);
        $order->revise($draft->customerId, $draft->warehouseId, $draft->orderDate, $draft->note, $draft->completeLines());
        $this->orders->save($order);

        $this->addFlash('success', 'Zapisano zamówienie.');

        return $this->redirectToRoute('sales_order_show', ['id' => $order->getId()]);
    }

    /**
     * @return FormInterface<OrderDraft>
     */
    protected function instantiateForm(): FormInterface
    {
        $order = $this->existingOrder();

        return $this->createForm(
            SalesOrderType::class,
            null === $order ? new OrderDraft($this->clock->now()) : OrderDraft::fromOrder($order),
        );
    }

    private function existingOrder(): ?SalesOrder
    {
        if (null === $this->orderId) {
            return null;
        }

        $order = $this->orders->find($this->orderId) ?? throw $this->createNotFoundException();
        if (!$order->isDraft()) {
            throw $this->createAccessDeniedException('Zmieniać można tylko zamówienie w statusie szkicu.');
        }

        return $order;
    }
}
