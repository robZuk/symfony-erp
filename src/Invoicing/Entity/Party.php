<?php

namespace App\Invoicing\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Seller or buyer as printed on an invoice: a copy taken when the invoice was issued.
 */
#[ORM\Embeddable]
final readonly class Party
{
    public function __construct(
        #[ORM\Column(length: 200)]
        public string $name,
        #[ORM\Column(length: 10, nullable: true)]
        public ?string $nip,
        #[ORM\Column(length: 200)]
        public string $street,
        #[ORM\Column(length: 12)]
        public string $postalCode,
        #[ORM\Column(length: 100)]
        public string $city,
        #[ORM\Column(length: 2)]
        public string $countryCode,
    ) {
    }
}
