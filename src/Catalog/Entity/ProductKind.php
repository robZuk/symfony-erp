<?php

namespace App\Catalog\Entity;

/**
 * Goods are kept in stock; services are sold and invoiced but never touch the warehouse.
 */
enum ProductKind: string
{
    case Goods = 'goods';
    case Service = 'service';

    public function label(): string
    {
        return match ($this) {
            self::Goods => 'Towar',
            self::Service => 'Usługa',
        };
    }
}
