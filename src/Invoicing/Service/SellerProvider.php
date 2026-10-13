<?php

namespace App\Invoicing\Service;

use App\Invoicing\Entity\Party;
use App\Invoicing\Entity\Seller;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The company issuing the invoices. An administrator edits it under "Dane firmy";
 * until that is done for the first time, the defaults from "app.seller" in config/services.yaml apply.
 */
final class SellerProvider
{
    /**
     * @param array{name: string, nip: string, street: string, postal_code: string, city: string, country_code: string, bank_account?: string|null} $defaults
     */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        #[Autowire(param: 'app.seller')]
        private readonly array $defaults,
    ) {
    }

    /**
     * The company as it is set now: the stored row, or a new one filled with the defaults.
     */
    public function seller(): Seller
    {
        $seller = $this->entityManager->find(Seller::class, Seller::ID);
        if (null !== $seller) {
            return $seller;
        }

        $seller = new Seller();
        $seller->setName($this->defaults['name']);
        $seller->setNip($this->defaults['nip']);
        $seller->setStreet($this->defaults['street']);
        $seller->setPostalCode($this->defaults['postal_code']);
        $seller->setCity($this->defaults['city']);
        $seller->setBankAccount($this->defaults['bank_account'] ?? null);

        return $seller;
    }

    public function save(Seller $seller): void
    {
        $this->entityManager->persist($seller);
        $this->entityManager->flush();
    }

    public function party(): Party
    {
        $seller = $this->seller();

        return new Party($seller->getName(), $seller->getNip(), $seller->getStreet(), $seller->getPostalCode(), $seller->getCity(), 'PL');
    }

    public function bankAccount(): ?string
    {
        return $this->seller()->getBankAccount();
    }
}
