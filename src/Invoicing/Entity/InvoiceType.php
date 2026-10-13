<?php

namespace App\Invoicing\Entity;

enum InvoiceType: string
{
    case Invoice = 'invoice';
    case Correction = 'correction';

    public function label(): string
    {
        return match ($this) {
            self::Invoice => 'Faktura VAT',
            self::Correction => 'Faktura korygująca',
        };
    }

    /**
     * Prefix of the document number; each type is numbered separately.
     */
    public function numberSeries(): string
    {
        return match ($this) {
            self::Invoice => 'FV',
            self::Correction => 'FK',
        };
    }
}
