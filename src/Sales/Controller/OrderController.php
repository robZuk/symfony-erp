<?php

namespace App\Sales\Controller;

use App\Sales\Dto\OrderFilter;
use App\Sales\Entity\SalesOrder;
use App\Sales\Repository\SalesOrderRepository;
use App\Sales\Service\OrderLifecycle;
use App\Shared\Catalog\ProductDirectory;
use App\Shared\Inventory\InsufficientStock;
use App\Shared\Inventory\WarehouseDirectory;
use App\Shared\Partner\PartnerDirectory;
use App\Shared\Twig\QuantityExtension;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/sales/orders', name: 'sales_order_')]
final class OrderController extends AbstractController
{
    private const string CSRF_TOKEN = 'sales_order';

    public function __construct(
        private readonly SalesOrderRepository $orders,
        private readonly OrderLifecycle $lifecycle,
        private readonly ProductDirectory $products,
        private readonly PartnerDirectory $partners,
        private readonly WarehouseDirectory $warehouses,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(#[MapQueryString] OrderFilter $filter = new OrderFilter()): Response
    {
        $page = $this->orders->search($filter);

        $customerIds = [];
        foreach ($page->items as $order) {
            $customerIds[] = $order->getCustomerId();
        }

        return $this->render('sales/order/index.html.twig', [
            'filter' => $filter,
            'page' => $page,
            'customers' => $this->partners->findMany($customerIds),
        ]);
    }

    #[Route('/new', name: 'new', methods: ['GET'])]
    #[IsGranted('ROLE_SALES_EDIT')]
    public function new(): Response
    {
        return $this->render('sales/order/edit.html.twig', ['order' => null]);
    }

    #[Route('/{id}/edit', name: 'edit', methods: ['GET'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_SALES_EDIT')]
    public function edit(SalesOrder $order): Response
    {
        if (!$order->isDraft()) {
            $this->addFlash('error', 'Zmieniać można tylko zamówienie w statusie szkicu.');

            return $this->redirectToShow($order);
        }

        return $this->render('sales/order/edit.html.twig', ['order' => $order]);
    }

    #[Route('/{id}', name: 'show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(SalesOrder $order): Response
    {
        return $this->render('sales/order/show.html.twig', [
            'order' => $order,
            'products' => $this->products->findMany(array_keys($order->quantitiesByProduct())),
            'customer' => $this->partners->find($order->getCustomerId()),
            'warehouse' => $this->warehouses->find($order->getWarehouseId()),
            'csrf_token_id' => self::CSRF_TOKEN,
        ]);
    }

    #[Route('/{id}/confirm', name: 'confirm', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_SALES_EDIT')]
    #[IsCsrfTokenValid(self::CSRF_TOKEN)]
    public function confirm(SalesOrder $order, QuantityExtension $quantity): Response
    {
        try {
            $this->lifecycle->confirm($order);
            $this->addFlash('success', \sprintf('Potwierdzono zamówienie %s. Towar został zarezerwowany.', $order->getNumber()));
        } catch (InsufficientStock $exception) {
            $products = $this->products->findMany(array_keys($exception->shortages));
            foreach ($exception->shortages as $productId => $shortage) {
                $this->addFlash('error', \sprintf(
                    'Brak towaru %s: potrzeba %s, dostępne %s.',
                    isset($products[$productId]) ? $products[$productId]->sku : '#'.$productId,
                    $quantity->format($shortage['requested']),
                    $quantity->format($shortage['available']),
                ));
            }
        } catch (\DomainException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToShow($order);
    }

    /**
     * Goods leave the warehouse, so this is the warehouse keeper's step, not the salesperson's.
     */
    #[Route('/{id}/ship', name: 'ship', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_INVENTORY_EDIT')]
    #[IsCsrfTokenValid(self::CSRF_TOKEN)]
    public function ship(SalesOrder $order): Response
    {
        try {
            $this->lifecycle->ship($order);
            $this->addFlash('success', null === $order->getShipmentNumber()
                ? 'Zamówienie oznaczono jako wydane.'
                : \sprintf('Wydano towar dokumentem %s.', $order->getShipmentNumber()));
        } catch (\DomainException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToShow($order);
    }

    #[Route('/{id}/cancel', name: 'cancel', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_SALES_EDIT')]
    #[IsCsrfTokenValid(self::CSRF_TOKEN)]
    public function cancel(SalesOrder $order): Response
    {
        try {
            $this->lifecycle->cancel($order);
            $this->addFlash('success', 'Anulowano zamówienie.');
        } catch (\DomainException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToShow($order);
    }

    private function redirectToShow(SalesOrder $order): Response
    {
        return $this->redirectToRoute('sales_order_show', ['id' => $order->getId()], Response::HTTP_SEE_OTHER);
    }
}
