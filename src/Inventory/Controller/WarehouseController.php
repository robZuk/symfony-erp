<?php

namespace App\Inventory\Controller;

use App\Inventory\Entity\Warehouse;
use App\Inventory\Form\WarehouseType;
use App\Inventory\Repository\WarehouseRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/inventory/warehouses', name: 'inventory_warehouse_')]
final class WarehouseController extends AbstractController
{
    public function __construct(private readonly WarehouseRepository $warehouses)
    {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('inventory/warehouse/index.html.twig', [
            'warehouses' => $this->warehouses->findAllOrdered(),
        ]);
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_INVENTORY_EDIT')]
    public function new(Request $request): Response
    {
        return $this->edit($request, new Warehouse());
    }

    #[Route('/{id}/edit', name: 'edit', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_INVENTORY_EDIT')]
    public function edit(Request $request, Warehouse $warehouse): Response
    {
        $form = $this->createForm(WarehouseType::class, $warehouse);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->warehouses->save($warehouse);
            $this->addFlash('success', \sprintf('Zapisano magazyn %s.', $warehouse->getCode()));

            return $this->redirectToRoute('inventory_warehouse_index', status: Response::HTTP_SEE_OTHER);
        }

        return $this->render('inventory/warehouse/form.html.twig', [
            'form' => $form,
            'warehouse' => $warehouse,
        ]);
    }

    /**
     * A closed warehouse keeps its history and stock but cannot be chosen on new documents.
     */
    #[Route('/{id}/toggle', name: 'toggle', methods: ['POST'])]
    #[IsGranted('ROLE_INVENTORY_EDIT')]
    #[IsCsrfTokenValid('inventory_warehouse_toggle')]
    public function toggle(Warehouse $warehouse): Response
    {
        $warehouse->setActive(!$warehouse->isActive());
        $this->warehouses->save($warehouse);
        $this->addFlash('success', \sprintf(
            $warehouse->isActive() ? 'Otwarto magazyn %s.' : 'Zamknięto magazyn %s.',
            $warehouse->getCode(),
        ));

        return $this->redirectToRoute('inventory_warehouse_index', status: Response::HTTP_SEE_OTHER);
    }
}
