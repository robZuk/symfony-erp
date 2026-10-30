<?php

namespace App\Sales\Api;

use OpenApi\Attributes as OA;

final readonly class OrderLineResource
{
    public function __construct(
        #[OA\Property(example: 'KAB-HDMI-2M')]
        public string $sku,
        #[OA\Property(example: '2')]
        public string $quantity,
        #[OA\Property(description: 'Net price of one unit agreed on the order, in PLN', example: '34.90')]
        public string $unitNetPrice,
        #[OA\Property(example: '23')]
        public string $vatRate,
        #[OA\Property(example: '69.80')]
        public string $netValue,
    ) {
    }
}
