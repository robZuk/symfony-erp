<?php

namespace App\Inventory\Entity;

enum DocumentStatus: string
{
    /** Can still be edited or deleted; has no effect on stock. */
    case Draft = 'draft';
    /** Has changed the stock and can no longer be modified. */
    case Posted = 'posted';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Szkic',
            self::Posted => 'Zaksięgowany',
        };
    }
}
