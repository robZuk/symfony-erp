<?php

namespace App\Inventory\Dto;

use App\Inventory\Entity\DocumentType;
use App\Inventory\Entity\Warehouse;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * What the user fills in to start a warehouse document. Once it is valid,
 * a StockDocument is built from it, so the entity itself is never half-filled.
 */
final class DocumentHeader
{
    #[Assert\NotNull(message: 'Wybierz magazyn.')]
    public ?Warehouse $warehouse = null;

    public ?Warehouse $targetWarehouse = null;

    public ?int $partnerId = null;

    #[Assert\NotNull(message: 'Podaj datę dokumentu.')]
    public ?\DateTimeImmutable $documentDate;

    #[Assert\Length(max: 1000)]
    public ?string $note = null;

    public function __construct(
        public readonly DocumentType $type,
        \DateTimeImmutable $today,
    ) {
        $this->documentDate = $today->setTime(0, 0);
    }

    #[Assert\Callback]
    public function validateTargetWarehouse(ExecutionContextInterface $context): void
    {
        if (DocumentType::Transfer !== $this->type) {
            return;
        }

        if (null === $this->targetWarehouse) {
            $context->buildViolation('Wybierz magazyn docelowy.')->atPath('targetWarehouse')->addViolation();
        } elseif ($this->targetWarehouse === $this->warehouse) {
            $context->buildViolation('Magazyn docelowy musi być inny niż źródłowy.')->atPath('targetWarehouse')->addViolation();
        }
    }
}
