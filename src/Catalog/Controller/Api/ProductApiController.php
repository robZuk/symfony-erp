<?php

namespace App\Catalog\Controller\Api;

use App\Catalog\Api\ProductResource;
use App\Catalog\Dto\ProductFilter;
use App\Catalog\Entity\Product;
use App\Catalog\Repository\ProductRepository;
use App\Catalog\Search\ProductFinder;
use App\Shared\Api\ApiController;
use App\Shared\Api\PageResource;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Product catalog for other systems. The same search as the product list on screen: words go
 * through Elasticsearch, and the database answers when it is down.
 */
#[Route('/api/products', name: 'api_product_', format: 'json')]
#[IsGranted('ROLE_API_CATALOG_READ')]
#[OA\Tag(name: 'Katalog')]
final class ProductApiController extends ApiController
{
    #[Route('', name: 'index', methods: ['GET'])]
    #[OA\Get(summary: 'Lista produktów, z wyszukiwaniem po nazwie lub indeksie', description: 'Domyślnie tylko aktywne. Fraza w `q` wybacza literówki i pominięte polskie znaki. Stronami po 20.')]
    #[OA\Response(response: 200, description: 'Jedna strona produktów', content: new OA\JsonContent(properties: [
        new OA\Property(property: 'items', type: 'array', items: new OA\Items(ref: new Model(type: ProductResource::class))),
        new OA\Property(property: 'page', type: 'integer', example: 1),
        new OA\Property(property: 'perPage', type: 'integer', example: 20),
        new OA\Property(property: 'total', type: 'integer', example: 42),
    ]))]
    #[OA\Response(response: 422, description: 'Błędny parametr, np. page=0')]
    public function index(ProductFinder $finder, #[MapQueryString(validationFailedStatusCode: 422)] ProductFilter $filter = new ProductFilter()): JsonResponse
    {
        return $this->respond(PageResource::of($finder->find($filter)->page, ProductResource::of(...)));
    }

    #[Route('/{sku}', name: 'show', methods: ['GET'], requirements: ['sku' => '[A-Za-z0-9._\/-]+'])]
    #[OA\Get(summary: 'Jeden produkt po indeksie (SKU)', description: 'Także wycofany ze sprzedaży: zamówienia sprzed wycofania wciąż go wskazują.')]
    #[OA\Response(response: 200, description: 'Produkt', content: new Model(type: ProductResource::class))]
    #[OA\Response(response: 404, description: 'Nie ma produktu o tym indeksie')]
    public function show(string $sku, ProductRepository $products): JsonResponse
    {
        $product = $products->findOneBy(['sku' => mb_strtoupper($sku)]);
        if (!$product instanceof Product) {
            throw $this->createNotFoundException(\sprintf('Nie ma produktu o indeksie %s.', $sku));
        }

        return $this->respond(ProductResource::of($product));
    }
}
