<?php

namespace App\Sales\Dto;

use App\Shared\Validator\Nip;
use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Body of POST /api/orders. The client names what it knows (a NIP, a warehouse code, SKUs);
 * prices and VAT rates come from the catalog, never from the client.
 */
final class PlaceOrderRequest
{
    #[Assert\NotBlank(message: 'Podaj NIP odbiorcy.')]
    #[Nip]
    #[OA\Property(description: 'NIP of a customer known to the ERP', example: '5260250274')]
    public string $customerNip = '';

    #[Assert\NotBlank(message: 'Podaj kod magazynu.')]
    #[Assert\Length(max: 10)]
    #[OA\Property(description: 'Code of the warehouse to ship from', example: 'MG')]
    public string $warehouse = '';

    #[Assert\Length(max: 500)]
    #[OA\Property(description: 'E.g. the order number in the shop', example: 'Zamówienie ze sklepu #10045')]
    public ?string $note = null;

    /** @var list<PlaceOrderLine> */
    #[Assert\Count(min: 1, max: 100, minMessage: 'Zamówienie musi mieć co najmniej jedną pozycję.', maxMessage: 'Najwyżej 100 pozycji w jednym zamówieniu.')]
    #[Assert\Valid]
    public array $lines = [];

    #[Assert\IsTrue(message: 'Każdy indeks może wystąpić w zamówieniu tylko raz.')]
    public function hasDistinctSkus(): bool
    {
        $skus = array_map(static fn (PlaceOrderLine $line): string => mb_strtoupper(trim($line->sku)), $this->lines);

        return \count($skus) === \count(array_unique($skus));
    }
}
