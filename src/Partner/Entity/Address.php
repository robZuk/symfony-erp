<?php

namespace App\Partner\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Embeddable]
class Address
{
    #[ORM\Column(length: 200)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 200)]
    private string $street = '';

    #[ORM\Column(length: 12)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 12)]
    #[Assert\When(
        expression: 'this.getCountryCode() === "PL"',
        constraints: [new Assert\Regex('/^\d{2}-\d{3}$/', message: 'Kod pocztowy ma format 00-000.')],
    )]
    private string $postalCode = '';

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 100)]
    private string $city = '';

    #[ORM\Column(length: 2)]
    #[Assert\NotBlank]
    #[Assert\Country]
    private string $countryCode = 'PL';

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

    public function getCountryCode(): string
    {
        return $this->countryCode;
    }

    public function setCountryCode(?string $countryCode): void
    {
        $this->countryCode = mb_strtoupper(trim((string) $countryCode));
    }
}
