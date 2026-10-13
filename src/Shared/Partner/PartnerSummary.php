<?php

namespace App\Shared\Partner;

/**
 * What other modules may know about a business partner.
 */
final readonly class PartnerSummary
{
    public function __construct(
        public int $id,
        public string $name,
        public ?string $nip,
        public string $city,
    ) {
    }
}
