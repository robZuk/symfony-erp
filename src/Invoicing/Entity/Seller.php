<?php

namespace App\Invoicing\Entity;

use App\Shared\Validator\Nip;
use App\Shared\Validator\NipValidator;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The company that issues the invoices. There is exactly one row, with id 1.
 * Changing it affects new invoices only: issued ones carry their own copy.
 */
#[ORM\Entity]
#[ORM\Table(name: 'invoicing_seller')]
class Seller
{
    public const int ID = 1;

    #[ORM\Id]
    #[ORM\Column]
    #[ORM\GeneratedValue(strategy: 'NONE')]
    private int $id = self::ID;

    #[ORM\Column(length: 200)]
    #[Assert\NotBlank(message: 'Podaj nazwę firmy.')]
    #[Assert\Length(max: 200)]
    private string $name = '';

    #[ORM\Column(length: 10)]
    #[Assert\NotBlank(message: 'Podaj NIP.')]
    #[Nip]
    private string $nip = '';

    #[ORM\Column(length: 200)]
    #[Assert\NotBlank(message: 'Podaj ulicę i numer.')]
    #[Assert\Length(max: 200)]
    private string $street = '';

    #[ORM\Column(length: 12)]
    #[Assert\NotBlank(message: 'Podaj kod pocztowy.')]
    #[Assert\Regex('/^\d{2}-\d{3}$/', message: 'Kod pocztowy ma format 00-000.')]
    private string $postalCode = '';

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank(message: 'Podaj miejscowość.')]
    #[Assert\Length(max: 100)]
    private string $city = '';

    #[ORM\Column(length: 40, nullable: true)]
    #[Assert\Length(max: 40)]
    private ?string $bankAccount = null;

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(?string $name): void
    {
        $this->name = trim((string) $name);
    }

    public function getNip(): string
    {
        return $this->nip;
    }

    public function setNip(?string $nip): void
    {
        $this->nip = NipValidator::normalize((string) $nip);
    }

    public function getStreet(): string
    {
        return $this->street;
    }

    public function setStreet(?string $street): void
    {
        $this->street = trim((string) $street);
    }

    public function getPostalCode(): string
    {
        return $this->postalCode;
    }

    public function setPostalCode(?string $postalCode): void
    {
        $this->postalCode = trim((string) $postalCode);
    }

    public function getCity(): string
    {
        return $this->city;
    }

    public function setCity(?string $city): void
    {
        $this->city = trim((string) $city);
    }

    public function getBankAccount(): ?string
    {
        return $this->bankAccount;
    }

    public function setBankAccount(?string $bankAccount): void
    {
        $bankAccount = trim((string) $bankAccount);
        $this->bankAccount = '' === $bankAccount ? null : $bankAccount;
    }
}
