<?php

namespace App\Sales\Dto;

use App\Shared\Api\Decimal;
use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

final class PlaceOrderLine
{
    #[Assert\NotBlank(message: 'Podaj indeks produktu.')]
    #[OA\Property(example: 'KAB-HDMI-2M')]
    public string $sku = '';

    /** A decimal string in the product's unit, at most three decimals. */
    #[Assert\NotBlank(message: 'Podaj ilość.')]
    #[Assert\Regex('/^\d{1,9}(\.\d{1,3})?$/', message: 'Ilość podaj jako liczbę dodatnią z najwyżej trzema miejscami po kropce, np. "2.5".')]
    #[OA\Property(description: 'In the product\'s unit, as a decimal string', example: '2')]
    public string $quantity = '';

    /** "0" and "0.000" have the right form but are not a quantity anyone orders. */
    #[Assert\Callback]
    public function validatePositive(ExecutionContextInterface $context): void
    {
        // A malformed quantity already has its own message from the Regex constraint
        if (1 === preg_match('/^\d{1,9}(\.\d{1,3})?$/', $this->quantity) && null === Decimal::parseQuantity($this->quantity)) {
            $context->buildViolation('Ilość musi być większa od zera.')->atPath('quantity')->addViolation();
        }
    }

    /**
     * @return int thousandths of the unit; only called on a validated line
     */
    public function thousandths(): int
    {
        return Decimal::parseQuantity($this->quantity) ?? throw new \LogicException('The quantity was not validated.');
    }
}
