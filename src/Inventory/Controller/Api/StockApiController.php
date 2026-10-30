<?php

namespace App\Inventory\Controller\Api;

use App\Inventory\Api\StockResource;
use App\Inventory\Dto\StockQuery;
use App\Inventory\Repository\StockLevelRepository;
use App\Shared\Api\ApiController;
use App\Shared\Api\ProblemDetails;
use App\Shared\Catalog\ProductDirectory;
use App\Shared\Catalog\ProductSummary;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/stock', name: 'api_stock_', format: 'json')]
#[IsGranted('ROLE_API_STOCK_READ')]
#[OA\Tag(name: 'Magazyn')]
final class StockApiController extends ApiController
{
    #[Route('', name: 'index', methods: ['GET'])]
    #[OA\Get(summary: 'Stany magazynowe produktów', description: 'Najwyżej 100 indeksów naraz, oddzielonych przecinkami. Nieznane indeksy wracają w `unknown`, zamiast przerywać całe zapytanie.')]
    #[OA\Response(response: 200, description: 'Stany', content: new OA\JsonContent(properties: [
        new OA\Property(property: 'items', type: 'array', items: new OA\Items(ref: new Model(type: StockResource::class))),
        new OA\Property(property: 'unknown', type: 'array', items: new OA\Items(type: 'string'), example: ['NIEMA-1']),
    ]))]
    #[OA\Response(response: 422, description: 'Brak parametru sku albo za dużo indeksów')]
    public function index(
        ProductDirectory $products,
        StockLevelRepository $levels,
        // Without a query string at all Symfony skips validation and passes the default, hence the check below
        #[MapQueryString(validationFailedStatusCode: 422)] StockQuery $query = new StockQuery(),
    ): JsonResponse {
        $skus = $query->skus();
        if ([] === $skus) {
            return ProblemDetails::create(Response::HTTP_UNPROCESSABLE_ENTITY, 'Podaj co najmniej jeden indeks w parametrze sku, np. ?sku=KAB-HDMI-2M,LAD-65W.');
        }
        $found = $products->findBySkus($skus);

        $byProduct = [];
        foreach ($levels->forProducts(array_values(array_map(static fn (ProductSummary $product): int => $product->id, $found))) as $level) {
            $byProduct[$level->getProductId()][] = $level;
        }

        return $this->respond([
            'items' => array_values(array_map(
                static fn (ProductSummary $product): StockResource => StockResource::of($product, $byProduct[$product->id] ?? []),
                // In the order the client asked for
                array_filter(array_map(static fn (string $sku): ?ProductSummary => $found[$sku] ?? null, $skus)),
            )),
            'unknown' => array_values(array_diff($skus, array_keys($found))),
        ]);
    }
}
