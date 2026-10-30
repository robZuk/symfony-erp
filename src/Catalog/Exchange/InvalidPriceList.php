<?php

namespace App\Catalog\Exchange;

/**
 * The file cannot be read as a price list at all: empty, malformed XML, columns missing...
 * Problems of single rows are reported per row instead (PriceListRow).
 */
final class InvalidPriceList extends \DomainException
{
}
