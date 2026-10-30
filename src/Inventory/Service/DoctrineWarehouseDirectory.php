<?php

namespace App\Inventory\Service;

use App\Inventory\Entity\Warehouse;
use App\Inventory\Repository\WarehouseRepository;
use App\Shared\Inventory\WarehouseDirectory;
use App\Shared\Inventory\WarehouseSummary;

final class DoctrineWarehouseDirectory implements WarehouseDirectory
{
    public function __construct(private readonly WarehouseRepository $warehouses)
    {
    }

    public function find(int $id): ?WarehouseSummary
    {
        $warehouse = $this->warehouses->find($id);

        return null === $warehouse ? null : self::summarize($warehouse);
    }

    public function findByCode(string $code): ?WarehouseSummary
    {
        $warehouse = $this->warehouses->findOneBy(['code' => mb_strtoupper(trim($code)), 'active' => true]);

        return null === $warehouse ? null : self::summarize($warehouse);
    }

    public function active(): array
    {
        return array_map(self::summarize(...), $this->warehouses->findBy(['active' => true], ['code' => 'ASC']));
    }

    private static function summarize(Warehouse $warehouse): WarehouseSummary
    {
        return new WarehouseSummary((int) $warehouse->getId(), $warehouse->getCode(), $warehouse->getName());
    }
}
