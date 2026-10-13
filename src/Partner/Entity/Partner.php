<?php

namespace App\Partner\Entity;

use App\Partner\Repository\PartnerRepository;
use App\Shared\Validator\Nip;
use App\Shared\Validator\NipValidator;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A company or person we trade with. One partner can be both a customer and a supplier.
 */
#[ORM\Entity(repositoryClass: PartnerRepository::class)]
#[ORM\Table(name: 'partner_partner')]
#[UniqueEntity('nip', message: 'Kontrahent z tym numerem NIP już istnieje.')]
class Partner
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 200)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 200)]
    private string $name = '';

    /** Ten digits without separators; empty for private persons. */
    #[ORM\Column(length: 10, unique: true, nullable: true)]
    #[Nip]
    private ?string $nip = null;

    #[ORM\Column]
    private bool $customer = true;

    #[ORM\Column]
    private bool $supplier = false;

    #[ORM\Column(length: 180, nullable: true)]
    #[Assert\Email]
    #[Assert\Length(max: 180)]
    private ?string $email = null;

    #[ORM\Column(length: 30, nullable: true)]
    #[Assert\Length(max: 30)]
    private ?string $phone = null;

    #[ORM\Embedded(class: Address::class)]
    #[Assert\Valid]
    private Address $address;

    #[ORM\Column]
    private bool $active = true;

    public function __construct()
    {
        $this->address = new Address();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(?string $name): void
    {
        $this->name = trim((string) $name);
    }

    public function getNip(): ?string
    {
        return $this->nip;
    }

    public function setNip(?string $nip): void
    {
        $nip = NipValidator::normalize((string) $nip);
        $this->nip = '' === $nip ? null : $nip;
    }

    public function isCustomer(): bool
    {
        return $this->customer;
    }

    public function setCustomer(bool $customer): void
    {
        $this->customer = $customer;
    }

    public function isSupplier(): bool
    {
        return $this->supplier;
    }

    public function setSupplier(bool $supplier): void
    {
        $this->supplier = $supplier;
    }

    #[Assert\IsTrue(message: 'Zaznacz, czy kontrahent jest odbiorcą, dostawcą, czy jednym i drugim.')]
    public function isCustomerOrSupplier(): bool
    {
        return $this->customer || $this->supplier;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): void
    {
        $email = mb_strtolower(trim((string) $email));
        $this->email = '' === $email ? null : $email;
    }

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function setPhone(?string $phone): void
    {
        $phone = trim((string) $phone);
        $this->phone = '' === $phone ? null : $phone;
    }

    public function getAddress(): Address
    {
        return $this->address;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): void
    {
        $this->active = $active;
    }
}
