<?php

namespace App\Partner\Vies;

/**
 * What the EU VAT register (VIES) says about one VAT number.
 */
final readonly class VatCheck
{
    public function __construct(
        public string $countryCode,
        public string $vatNumber,
        /**
         * Registered for intra-Community transactions (VAT-UE in Poland). A company can be an active
         * VAT payer at home and still not be registered here, so false does not mean it does not exist.
         */
        public bool $valid,
        /** As registered; null when the member state does not disclose it. */
        public ?string $name,
        public ?string $address,
        public \DateTimeImmutable $checkedAt,
    ) {
    }
}
