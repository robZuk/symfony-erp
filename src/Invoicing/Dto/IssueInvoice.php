<?php

namespace App\Invoicing\Dto;

use App\Invoicing\Entity\PaymentMethod;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * What the user decides when issuing an invoice; everything else comes from the order.
 */
final class IssueInvoice
{
    #[Assert\NotNull(message: 'Podaj datę wystawienia.')]
    public ?\DateTimeImmutable $issueDate;

    #[Assert\NotNull(message: 'Podaj termin płatności w dniach.')]
    #[Assert\Range(min: 0, max: 365, notInRangeMessage: 'Termin płatności to od {{ min }} do {{ max }} dni.')]
    public ?int $paymentDays = 14;

    #[Assert\NotNull]
    public ?PaymentMethod $paymentMethod = PaymentMethod::Transfer;

    public function __construct(\DateTimeImmutable $today)
    {
        $this->issueDate = $today->setTime(0, 0);
    }
}
