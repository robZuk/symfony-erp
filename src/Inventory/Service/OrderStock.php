<?php

namespace App\Inventory\Service;

use App\Inventory\Entity\DocumentType;
use App\Inventory\Entity\StockDocument;
use App\Inventory\Entity\StockLevel;
use App\Inventory\Entity\Warehouse;
use App\Inventory\Repository\StockDocumentRepository;
use App\Inventory\Repository\StockLevelRepository;
use App\Inventory\Repository\WarehouseRepository;
use App\Shared\Inventory\InsufficientStock;
use App\Shared\Inventory\Shipment;
use App\Shared\Inventory\StockReservations;
use App\Shared\Persistence\Transactional;
use Psr\Clock\ClockInterface;

/**
 * The warehouse side of sales orders: reservations and the goods issue that ends them.
 */
final class OrderStock implements StockReservations
{
    public function __construct(
        private readonly Transactional $transactional,
        private readonly WarehouseRepository $warehouses,
        private readonly StockLevelRepository $stockLevels,
        private readonly StockDocumentRepository $documents,
        private readonly StockPosting $posting,
        private readonly ClockInterface $clock,
    ) {
    }

    public function reserve(int $warehouseId, array $quantities): void
    {
        $this->transactional->run(function () use ($warehouseId, $quantities): void {
            $levels = $this->lockLevels($this->warehouse($warehouseId), $quantities);

            $shortages = [];
            foreach ($quantities as $productId => $requested) {
                if ($requested > $levels[$productId]->available()) {
                    $shortages[$productId] = ['requested' => $requested, 'available' => $levels[$productId]->available()];
                }
            }
            if ([] !== $shortages) {
                throw new InsufficientStock($shortages);
            }

            foreach ($quantities as $productId => $quantity) {
                $levels[$productId]->reserve($quantity);
            }
        });
    }

    public function release(int $warehouseId, array $quantities): void
    {
        $this->transactional->run(function () use ($warehouseId, $quantities): void {
            $levels = $this->lockLevels($this->warehouse($warehouseId), $quantities);

            foreach ($quantities as $productId => $quantity) {
                $levels[$productId]->release($quantity);
            }
        });
    }

    public function ship(int $warehouseId, ?int $partnerId, array $quantities, string $note): Shipment
    {
        return $this->transactional->run(function () use ($warehouseId, $partnerId, $quantities, $note): Shipment {
            $warehouse = $this->warehouse($warehouseId);
            $now = $this->clock->now();

            // The reserved goods become available again for a moment, and the issue below takes them.
            // Both happen in one transaction with the rows locked, so nobody can slip in between.
            $levels = $this->lockLevels($warehouse, $quantities);
            foreach ($quantities as $productId => $quantity) {
                $levels[$productId]->release($quantity);
            }

            $document = new StockDocument(DocumentType::Issue, $warehouse, $now, $now);
            $document->setPartnerId($partnerId);
            $document->setNote($note);
            foreach ($quantities as $productId => $quantity) {
                $document->addLine($productId, $quantity);
            }
            $this->documents->save($document);
            $this->posting->post($document);

            return new Shipment(
                (int) $document->getId(),
                $document->getNumber() ?? throw new \LogicException('A posted document always has a number.'),
            );
        });
    }

    private function warehouse(int $id): Warehouse
    {
        return $this->warehouses->find($id) ?? throw new \DomainException('Magazyn nie istnieje.');
    }

    /**
     * @param array<int, int> $quantities
     *
     * @return array<int, StockLevel> indexed by product id
     */
    private function lockLevels(Warehouse $warehouse, array $quantities): array
    {
        if ([] === $quantities) {
            return [];
        }

        return $this->stockLevels->lockForUpdate([$warehouse], array_keys($quantities))[(int) $warehouse->getId()];
    }
}
