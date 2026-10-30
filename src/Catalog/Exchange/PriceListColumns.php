<?php

namespace App\Catalog\Exchange;

/**
 * Columns of the price list, in the order they are exported. Headers are Polish, as people edit the
 * file in a spreadsheet; when reading, case does not matter and a few other names are accepted too.
 * In XML each column is an element of <produkt>.
 */
final class PriceListColumns
{
    public const string SKU = 'indeks';
    public const string NAME = 'nazwa';
    public const string KIND = 'rodzaj';
    public const string UNIT = 'jednostka';
    public const string NET_PRICE = 'cena_netto';
    public const string VAT_RATE = 'stawka_vat';
    public const string MINIMUM_STOCK = 'stan_minimalny';
    public const string ACTIVE = 'aktywny';

    /** Every column, in export order. */
    public const array ALL = [self::SKU, self::NAME, self::KIND, self::UNIT, self::NET_PRICE, self::VAT_RATE, self::MINIMUM_STOCK, self::ACTIVE];

    /** A file without these cannot be imported; the others keep their current value, or the default for a new product. */
    public const array REQUIRED = [self::SKU, self::NAME, self::NET_PRICE, self::VAT_RATE];

    private const array ALIASES = [
        'sku' => self::SKU,
        'kod' => self::SKU,
        'name' => self::NAME,
        'kind' => self::KIND,
        'typ' => self::KIND,
        'jm' => self::UNIT,
        'unit' => self::UNIT,
        'cena' => self::NET_PRICE,
        'net_price' => self::NET_PRICE,
        'vat' => self::VAT_RATE,
        'minimum_stock' => self::MINIMUM_STOCK,
        'active' => self::ACTIVE,
    ];

    /**
     * The column a header stands for, or null for a column the import ignores.
     */
    public static function fromHeader(string $header): ?string
    {
        $name = str_replace([' ', '-'], '_', mb_strtolower(trim($header)));

        return \in_array($name, self::ALL, true) ? $name : (self::ALIASES[$name] ?? null);
    }
}
