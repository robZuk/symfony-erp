<?php

namespace App\Sales\Entity;

/**
 * Places of the "sales_order" workflow (config/packages/workflow.yaml).
 */
enum OrderStatus: string
{
    /** Being written; nothing is promised to the customer yet. */
    case Draft = 'draft';
    /** Accepted: it has a number and its goods are reserved. */
    case Confirmed = 'confirmed';
    /** Goods have left the warehouse. */
    case Shipped = 'shipped';
    case Invoiced = 'invoiced';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Szkic',
            self::Confirmed => 'Potwierdzone',
            self::Shipped => 'Wydane',
            self::Invoiced => 'Zafakturowane',
            self::Cancelled => 'Anulowane',
        };
    }
}
