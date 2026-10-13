<?php

namespace App\Invoicing\Service;

use App\Invoicing\Entity\Party;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The company issuing the invoices, configured under "app.seller" in config/services.yaml.
 */
final class SellerProvider
{
    /**
     * @param array{name: string, nip: string, street: string, postal_code: string, city: string, country_code: string, bank_account?: string|null} $seller
     */
    public function __construct(
        #[Autowire(param: 'app.seller')]
        private readonly array $seller,
    ) {
    }

    public function party(): Party
    {
        return new Party(
            $this->seller['name'],
            $this->seller['nip'],
            $this->seller['street'],
            $this->seller['postal_code'],
            $this->seller['city'],
            $this->seller['country_code'],
        );
    }

    public function bankAccount(): ?string
    {
        return $this->seller['bank_account'] ?? null;
    }
}
