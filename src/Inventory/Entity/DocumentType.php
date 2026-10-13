<?php

namespace App\Inventory\Entity;

enum DocumentType: string
{
    /** Przyjęcie zewnętrzne: goods arrive from a supplier. */
    case Receipt = 'PZ';
    /** Wydanie zewnętrzne: goods leave to a customer. */
    case Issue = 'WZ';
    /** Przesunięcie międzymagazynowe: goods move between two warehouses. */
    case Transfer = 'MM';

    public function label(): string
    {
        return match ($this) {
            self::Receipt => 'Przyjęcie zewnętrzne',
            self::Issue => 'Wydanie zewnętrzne',
            self::Transfer => 'Przesunięcie międzymagazynowe',
        };
    }

    /**
     * The document that undoes this one.
     */
    public function opposite(): self
    {
        return match ($this) {
            self::Receipt => self::Issue,
            self::Issue => self::Receipt,
            self::Transfer => self::Transfer,
        };
    }
}
