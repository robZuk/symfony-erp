<?php

namespace App\Catalog\Exchange;

/**
 * File formats of the price list: the same columns either way.
 */
enum PriceListFormat: string
{
    case Csv = 'csv';
    case Xml = 'xml';

    public static function fromFileName(string $fileName): ?self
    {
        return self::tryFrom(strtolower(pathinfo($fileName, \PATHINFO_EXTENSION)));
    }

    public function contentType(): string
    {
        return match ($this) {
            self::Csv => 'text/csv; charset=UTF-8',
            self::Xml => 'application/xml; charset=UTF-8',
        };
    }
}
