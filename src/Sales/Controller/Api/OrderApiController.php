<?php

namespace App\Sales\Controller\Api;

use App\Sales\Api\OrderResource;
use App\Sales\Dto\PlaceOrderRequest;
use App\Sales\Entity\SalesOrder;
use App\Sales\Repository\ApiOrderRequestRepository;
use App\Sales\Service\IdempotencyKeyReused;
use App\Sales\Service\OrderPlacement;
use App\Sales\Service\UnresolvedReferences;
use App\Shared\Api\ApiConsumer;
use App\Shared\Api\ApiController;
use App\Shared\Api\Decimal;
use App\Shared\Api\ProblemDetails;
use App\Shared\Catalog\ProductDirectory;
use App\Shared\Inventory\InsufficientStock;
use App\Shared\Inventory\WarehouseDirectory;
use App\Shared\Partner\PartnerDirectory;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/orders', name: 'api_order_', format: 'json')]
#[OA\Tag(name: 'Zamówienia')]
final class OrderApiController extends ApiController
{
    public function __construct(
        private readonly PartnerDirectory $partners,
        private readonly WarehouseDirectory $warehouses,
        private readonly ProductDirectory $products,
    ) {
    }

    #[Route('', name: 'place', methods: ['POST'])]
    #[IsGranted('ROLE_API_ORDERS_WRITE')]
    #[OA\Post(
        summary: 'Złożenie zamówienia',
        description: "Zamówienie od razu jest potwierdzone, a towar zarezerwowany, jak po kliknięciu „Potwierdź”. Ceny i stawki VAT pochodzą z katalogu.\n\n"
            .'Nagłówek `Idempotency-Key` (np. UUID, unikalny dla każdego zamówienia) jest wymagany. Ponowienie żądania z tym samym kluczem '
            .'i tą samą treścią zwraca już złożone zamówienie (200, `Idempotent-Replayed: true`), zamiast składać drugie.',
        parameters: [new OA\HeaderParameter(name: 'Idempotency-Key', required: true, schema: new OA\Schema(type: 'string', maxLength: 100), example: '7d3c6a9e-2f1b-4c8e-9a51-0e6f4b2d1c90')],
    )]
    #[OA\Response(response: 201, description: 'Zamówienie złożone; adres w nagłówku Location', content: new Model(type: OrderResource::class))]
    #[OA\Response(response: 200, description: 'Ponowione żądanie: to samo, wcześniej złożone zamówienie', content: new Model(type: OrderResource::class))]
    #[OA\Response(response: 400, description: 'Brak nagłówka Idempotency-Key')]
    #[OA\Response(response: 409, description: 'Za mało towaru; nic nie zostało zapisane. Lista braków w `shortages`.')]
    #[OA\Response(response: 422, description: 'Błędne dane, nieznany odbiorca, magazyn lub produkt, albo klucz Idempotency-Key użyty dla innej treści')]
    public function place(
        Request $request,
        OrderPlacement $placement,
        #[CurrentUser] ApiConsumer $client,
        #[MapRequestPayload(validationFailedStatusCode: 422)] PlaceOrderRequest $order,
    ): JsonResponse {
        $key = trim((string) $request->headers->get('Idempotency-Key'));
        if ('' === $key || \strlen($key) > 100) {
            return ProblemDetails::create(Response::HTTP_BAD_REQUEST, 'Wymagany nagłówek Idempotency-Key (do 100 znaków), unikalny dla każdego zamówienia, np. UUID.');
        }

        try {
            // The body as received, re-encoded so that whitespace and formatting do not count
            $placed = $placement->place($client, $key, hash('sha256', (string) json_encode($request->toArray())), $order);
        } catch (InsufficientStock $exception) {
            return ProblemDetails::create(Response::HTTP_CONFLICT, $exception->getMessage(), ['shortages' => $this->shortages($exception)]);
        } catch (UnresolvedReferences $exception) {
            return ProblemDetails::create(Response::HTTP_UNPROCESSABLE_ENTITY, $exception->getMessage(), ['violations' => $exception->problems]);
        } catch (IdempotencyKeyReused $exception) {
            return ProblemDetails::create(Response::HTTP_UNPROCESSABLE_ENTITY, $exception->getMessage());
        }

        $location = $this->generateUrl('api_order_show', ['id' => $placed->order->getId()]);

        return $placed->replayed
            ? $this->respond($this->resource($placed->order), Response::HTTP_OK, ['Location' => $location, 'Idempotent-Replayed' => 'true'])
            : $this->respond($this->resource($placed->order), Response::HTTP_CREATED, ['Location' => $location]);
    }

    #[Route('/{id}', name: 'show', methods: ['GET'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_API_ORDERS_READ')]
    #[OA\Get(summary: 'Zamówienie i jego status', description: 'Tylko zamówienia złożone tym samym kluczem API; cudze nie istnieją (404).')]
    #[OA\Response(response: 200, description: 'Zamówienie', content: new Model(type: OrderResource::class))]
    #[OA\Response(response: 404, description: 'Nie ma takiego zamówienia złożonego tym kluczem')]
    public function show(int $id, ApiOrderRequestRepository $requests, #[CurrentUser] ApiConsumer $client): JsonResponse
    {
        // 404 rather than 403 for another client's order: not even its existence is revealed
        $order = $requests->findOrderPlacedBy($client->apiConsumerId(), $id)
            ?? throw $this->createNotFoundException(\sprintf('Nie ma zamówienia %d złożonego tym kluczem API.', $id));

        return $this->respond($this->resource($order));
    }

    private function resource(SalesOrder $order): OrderResource
    {
        return OrderResource::of(
            $order,
            $this->partners->find($order->getCustomerId()),
            $this->warehouses->find($order->getWarehouseId()),
            $this->products->findMany(array_values(array_unique(array_map(static fn ($line): int => $line->getProductId(), $order->getLines()->toArray())))),
        );
    }

    /**
     * @return list<array{sku: string, requested: string, available: string}>
     */
    private function shortages(InsufficientStock $exception): array
    {
        $products = $this->products->findMany(array_keys($exception->shortages));
        $shortages = [];
        foreach ($exception->shortages as $productId => $shortage) {
            $shortages[] = [
                'sku' => isset($products[$productId]) ? $products[$productId]->sku : '?',
                'requested' => Decimal::quantity($shortage['requested']),
                'available' => Decimal::quantity($shortage['available']),
            ];
        }

        return $shortages;
    }
}
