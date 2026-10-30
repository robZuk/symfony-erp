<?php

namespace App\Catalog\Exchange;

use App\Catalog\Entity\Product;

/**
 * Writes the catalog in the price list format, the one ProductImporter reads: export, edit in a
 * spreadsheet, import again.
 *
 * CSV is written for a Polish spreadsheet: a UTF-8 byte order mark (or Excel takes it for
 * Windows-1250), semicolons, decimal commas. Both formats are written as the products come,
 * never holding the whole file in memory.
 */
final class PriceListWriter
{
    private const int FLUSH_EVERY = 200;

    /**
     * @param iterable<Product> $products
     * @param resource          $out
     */
    public function write(iterable $products, PriceListFormat $format, $out): void
    {
        match ($format) {
            PriceListFormat::Csv => $this->csv($products, $out),
            PriceListFormat::Xml => $this->xml($products, $out),
        };
    }

    /**
     * @param iterable<Product> $products
     * @param resource          $out
     */
    private function csv(iterable $products, $out): void
    {
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, PriceListColumns::ALL, ';', '"', '');
        foreach ($products as $product) {
            fputcsv($out, array_values(self::values($product, ',')), ';', '"', '');
        }
    }

    /**
     * @param iterable<Product> $products
     * @param resource          $out
     */
    private function xml(iterable $products, $out): void
    {
        $xml = new \XMLWriter();
        $xml->openMemory();
        $xml->setIndent(true);
        $xml->startDocument('1.0', 'UTF-8');
        $xml->startElement('produkty');

        $count = 0;
        foreach ($products as $product) {
            $xml->startElement('produkt');
            foreach (self::values($product, '.') as $column => $value) {
                $xml->writeElement($column, $value);
            }
            $xml->endElement();

            if (0 === ++$count % self::FLUSH_EVERY) {
                fwrite($out, (string) $xml->flush());
            }
        }

        $xml->endElement();
        $xml->endDocument();
        fwrite($out, (string) $xml->flush());
    }

    /**
     * @return array<string, string> column => text, in the order of PriceListColumns::ALL
     */
    private static function values(Product $product, string $decimalSeparator): array
    {
        $decimal = static function (int $value, int $digits) use ($decimalSeparator): string {
            $text = \sprintf('%d%s%0'.$digits.'d', intdiv($value, 10 ** $digits), $decimalSeparator, $value % 10 ** $digits);

            // Quantities without trailing zeros ("2,5", "10"), amounts always with grosze ("34,90")
            return 3 === $digits ? rtrim(rtrim($text, '0'), $decimalSeparator) : $text;
        };

        return [
            PriceListColumns::SKU => $product->getSku(),
            PriceListColumns::NAME => $product->getName(),
            PriceListColumns::KIND => mb_strtolower($product->getKind()->label()),
            PriceListColumns::UNIT => $product->getUnit()->label(),
            PriceListColumns::NET_PRICE => $decimal($product->getNetPrice(), 2),
            PriceListColumns::VAT_RATE => $product->getVatRate()->value,
            PriceListColumns::MINIMUM_STOCK => $decimal($product->getMinimumStock(), 3),
            PriceListColumns::ACTIVE => $product->isActive() ? 'tak' : 'nie',
        ];
    }
}
