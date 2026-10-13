<?php

namespace App\Catalog\Entity;

use App\Catalog\Repository\ProductRepository;
use App\Shared\Tax\VatRate;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: ProductRepository::class)]
#[ORM\Table(name: 'catalog_product')]
#[UniqueEntity('sku', message: 'Produkt o tym indeksie już istnieje.')]
class Product
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 40, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 40)]
    #[Assert\Regex('/^[A-Z0-9][A-Z0-9._\/-]*$/', message: 'Indeks może zawierać litery, cyfry oraz znaki . _ - /')]
    private string $sku = '';

    #[ORM\Column(length: 200)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 200)]
    private string $name = '';

    #[ORM\Column(length: 10)]
    private ProductKind $kind = ProductKind::Goods;

    #[ORM\Column(length: 10)]
    private UnitOfMeasure $unit = UnitOfMeasure::Piece;

    #[ORM\Column(length: 5)]
    private VatRate $vatRate = VatRate::Standard;

    /** Net price for one unit, in grosze. */
    #[ORM\Column]
    #[Assert\PositiveOrZero]
    private int $netPrice = 0;

    /**
     * Stock level, in thousandths of the unit, below which the product should be reordered.
     * Zero means the product is not watched.
     */
    #[ORM\Column(options: ['default' => 0])]
    #[Assert\PositiveOrZero]
    private int $minimumStock = 0;

    #[ORM\Column]
    private bool $active = true;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSku(): string
    {
        return $this->sku;
    }

    public function setSku(?string $sku): void
    {
        $this->sku = mb_strtoupper(trim((string) $sku));
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(?string $name): void
    {
        $this->name = trim((string) $name);
    }

    public function getKind(): ProductKind
    {
        return $this->kind;
    }

    public function setKind(ProductKind $kind): void
    {
        $this->kind = $kind;
    }

    public function getUnit(): UnitOfMeasure
    {
        return $this->unit;
    }

    public function setUnit(UnitOfMeasure $unit): void
    {
        $this->unit = $unit;
    }

    public function getVatRate(): VatRate
    {
        return $this->vatRate;
    }

    public function setVatRate(VatRate $vatRate): void
    {
        $this->vatRate = $vatRate;
    }

    public function getNetPrice(): int
    {
        return $this->netPrice;
    }

    public function setNetPrice(?int $netPrice): void
    {
        $this->netPrice = $netPrice ?? 0;
    }

    public function getMinimumStock(): int
    {
        return $this->minimumStock;
    }

    public function setMinimumStock(?int $minimumStock): void
    {
        $this->minimumStock = $minimumStock ?? 0;
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
