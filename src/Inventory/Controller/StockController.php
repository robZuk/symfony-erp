<?php

namespace App\Inventory\Controller;

use App\Inventory\Dto\StockFilter;
use App\Inventory\Repository\StockLevelRepository;
use App\Inventory\Repository\StockMovementRepository;
use App\Inventory\Repository\WarehouseRepository;
use App\Shared\Catalog\ProductDirectory;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/inventory/stock', name: 'inventory_stock_')]
final class StockController extends AbstractController
{
    public function __construct(
        private readonly StockLevelRepository $stockLevels,
        private readonly ProductDirectory $products,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(
        WarehouseRepository $warehouses,
        #[MapQueryString] StockFilter $filter = new StockFilter(),
    ): Response {
        $matchingProducts = '' === trim($filter->q) ? null : $this->products->idsMatching($filter->q);
        $page = $this->stockLevels->search($filter, $matchingProducts);

        $productIds = [];
        foreach ($page->items as $level) {
            $productIds[] = $level->getProductId();
        }

        return $this->render('inventory/stock/index.html.twig', [
            'filter' => $filter,
            'page' => $page,
            'warehouses' => $warehouses->findAllOrdered(),
            'products' => $this->products->findMany($productIds),
        ]);
    }

    /**
     * Where one product is and how it got there.
     */
    #[Route('/products/{productId}', name: 'product', methods: ['GET'], requirements: ['productId' => '\d+'])]
    public function product(
        int $productId,
        StockMovementRepository $movements,
        #[MapQueryParameter(options: ['min_range' => 1])] int $page = 1,
    ): Response {
        $product = $this->products->find($productId) ?? throw $this->createNotFoundException();

        return $this->render('inventory/stock/product.html.twig', [
            'product' => $product,
            'levels' => $this->stockLevels->forProduct($productId),
            'page' => $movements->forProduct($productId, $page),
        ]);
    }
}
