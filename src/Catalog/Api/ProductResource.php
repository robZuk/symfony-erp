<?php

namespace App\Catalog\Api;

use App\Catalog\Entity\Product;
use App\Shared\Api\Decimal;
use OpenApi\Attributes as OA;

/**
 * A product as the REST API shows it. A class of its own and not the entity, so a change to the
 * entity cannot silently change what the API's clients receive.
 */
final readonly class ProductResource
{
    public function __construct(
        #[OA\Property(example: 'KAB-HDMI-2M')]
        public string $sku,
        #[OA\Property(example: 'Kabel HDMI 2.1, 2 m')]
        public string $name,
        #[OA\Property(description: 'goods (towar) or service (usługa)', enum: ['goods', 'service'])]
        public string $kind,
        #[OA\Property(description: 'Unit as printed on documents', example: 'szt.')]
        public string $unit,
        #[OA\Property(description: 'Net price of one unit in PLN, as a decimal string', example: '49.90')]
        public string $netPrice,
        #[OA\Property(description: 'VAT rate: 23, 8, 5, 0 or zw (exempt)', example: '23')]
        public string $vatRate,
        #[OA\Property(description: 'False for a product withdrawn from sale')]
        public bool $active,
    ) {
    }

    public static function of(Product $product): self
    {
        return new self(
            $product->getSku(),
            $product->getName(),
            $product->getKind()->value,
            $product->getUnit()->label(),
            Decimal::money($product->getNetPrice()),
            $product->getVatRate()->value,
            $product->isActive(),
        );
    }
}
