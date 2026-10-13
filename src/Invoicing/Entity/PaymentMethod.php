<?php

namespace App\Invoicing\Entity;

enum PaymentMethod: string
{
    case Transfer = 'transfer';
    case Cash = 'cash';
    case Card = 'card';

    public function label(): string
    {
        return match ($this) {
            self::Transfer => 'Przelew',
            self::Cash => 'Gotówka',
            self::Card => 'Karta',
        };
    }
}
