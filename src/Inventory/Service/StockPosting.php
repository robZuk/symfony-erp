<?php

namespace App\Inventory\Service;

use App\Inventory\Entity\DocumentType;
use App\Inventory\Entity\StockDocument;
use App\Inventory\Entity\StockLevel;
use App\Inventory\Entity\StockMovement;
use App\Inventory\Entity\Warehouse;
use App\Inventory\Repository\StockLevelRepository;
use App\Shared\Inventory\InsufficientStock;
use App\Shared\Numbering\DocumentNumberGenerator;
use App\Shared\Persistence\Transactional;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Posts a warehouse document: checks availability, writes the ledger, updates stock levels
 * and gives the document its number. Either all of it happens or none of it.
 */
final class StockPosting
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly Transactional $transactional,
        private readonly StockLevelRepository $stockLevels,
        private readonly DocumentNumberGenerator $numbers,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @throws InsufficientStock when goods would leave a warehouse that does not have them
     * @throws \DomainException  when the document cannot be posted at all
     */
    public function post(StockDocument $document): void
    {
        $this->transactional->run(fn () => $this->postInTransaction($document));
    }

    private function postInTransaction(StockDocument $document): void
    {
        // Lock the document row and reread it, so two people posting the same draft
        // at once cannot both get through the check below
        $this->entityManager->refresh($document, LockMode::PESSIMISTIC_WRITE);

        if (!$document->isDraft()) {
            throw new \DomainException('Ten dokument jest już zaksięgowany.');
        }

        $quantities = $document->quantitiesByProduct();
        if ([] === $quantities) {
            throw new \DomainException('Dokument bez pozycji nie może zostać zaksięgowany.');
        }

        $warehouse = $document->getWarehouse();
        $now = $this->clock->now();

        [$outOf, $into] = match ($document->getType()) {
            DocumentType::Receipt => [null, $warehouse],
            DocumentType::Issue => [$warehouse, null],
            DocumentType::Transfer => [
                $warehouse,
                $document->getTargetWarehouse() ?? throw new \LogicException('A transfer always has a target warehouse.'),
            ],
        };

        // Rows are locked until the transaction ends, so nobody else can take the same goods
        $levels = $this->stockLevels->lockForUpdate(
            array_values(array_filter([$outOf, $into])),
            array_keys($quantities),
        );

        if (null !== $outOf) {
            $this->assertAvailable($levels, $outOf, $quantities);
        }

        foreach ($quantities as $productId => $quantity) {
            if (null !== $outOf) {
                $this->move($levels, $outOf, $productId, -$quantity, $document, $now);
            }
            if (null !== $into) {
                $this->move($levels, $into, $productId, $quantity, $document, $now);
            }
        }

        $document->markPosted($this->numbers->next($document->getType()->value, $document->getDocumentDate()), $now);
    }

    /**
     * @param array<int, array<int, StockLevel>> $levels
     * @param array<int, int>                    $quantities
     */
    private function assertAvailable(array $levels, Warehouse $warehouse, array $quantities): void
    {
        $shortages = [];
        foreach ($quantities as $productId => $requested) {
            $available = $levels[(int) $warehouse->getId()][$productId]->available();
            if ($requested > $available) {
                $shortages[$productId] = ['requested' => $requested, 'available' => $available];
            }
        }

        if ([] !== $shortages) {
            throw new InsufficientStock($shortages);
        }
    }

    /**
     * @param array<int, array<int, StockLevel>> $levels
     */
    private function move(
        array $levels,
        Warehouse $warehouse,
        int $productId,
        int $delta,
        StockDocument $document,
        \DateTimeImmutable $now,
    ): void {
        $levels[(int) $warehouse->getId()][$productId]->apply($delta);
        $this->entityManager->persist(new StockMovement($warehouse, $productId, $delta, $document, $now));
    }
}
