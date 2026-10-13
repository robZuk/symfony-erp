<?php

namespace App\Sales\Dto;

use App\Shared\Money\Money;
use App\Shared\Tax\VatRate;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * One line of the order form, possibly still incomplete.
 */
final class OrderLineDraft
{
    #[Assert\NotBlank(message: 'Wybierz produkt.')]
    public ?int $productId = null;

    /** In thousandths of the product's unit. */
    #[Assert\NotBlank(message: 'Podaj ilość.')]
    #[Assert\Positive(message: 'Ilość musi być większa od zera.')]
    public ?int $quantity = null;

    /** In grosze. */
    #[Assert\NotNull(message: 'Podaj cenę.')]
    #[Assert\PositiveOrZero(message: 'Cena nie może być ujemna.')]
    public ?int $unitNetPrice = null;

    #[Assert\NotNull(message: 'Wybierz stawkę VAT.')]
    public ?VatRate $vatRate = null;

    /**
     * Net value of the line, or null while quantity or price is missing.
     */
    public function netValue(): ?Money
    {
        if (null === $this->quantity || null === $this->unitNetPrice || $this->quantity <= 0 || $this->unitNetPrice < 0) {
            return null;
        }

        return Money::ofMinor($this->unitNetPrice)->timesQuantity($this->quantity);
    }
}
