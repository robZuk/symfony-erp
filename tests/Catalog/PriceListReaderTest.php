<?php

namespace App\Tests\Catalog;

use App\Catalog\Exchange\InvalidPriceList;
use App\Catalog\Exchange\PriceListFormat;
use App\Catalog\Exchange\PriceListReader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PriceListReaderTest extends TestCase
{
    #[DataProvider('separators')]
    public function testCsvWithAnySeparatorTheHeaderUses(string $separator): void
    {
        $rows = $this->csv(implode($separator, ['indeks', 'nazwa', 'cena_netto', 'stawka_vat'])."\n".implode($separator, ['KAB-1', 'Kabel', '34,90', '23'])."\n");

        self::assertSame([2 => ['indeks' => 'KAB-1', 'nazwa' => 'Kabel', 'cena_netto' => '34,90', 'stawka_vat' => '23']], $rows);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function separators(): iterable
    {
        yield 'semicolon' => [';'];
        // The price has a decimal comma, which is why spreadsheets in Poland use semicolons
        yield 'tab' => ["\t"];
    }

    public function testCsvFromAPolishSpreadsheet(): void
    {
        // Byte order mark, Windows line ends, quoted separator, an empty line, Windows-1250 letters
        $csv = "\xEF\xBB\xBFIndeks;Nazwa;Cena netto;VAT;Uwagi\r\n"
            ."KAB-1;\"Kabel; HDMI\";34,90;23;x\r\n"
            ."\r\n"
            ."ZAR-1;\xAFar\xF3wka;9,99;23%;\r\n";

        self::assertSame([
            2 => ['indeks' => 'KAB-1', 'nazwa' => 'Kabel; HDMI', 'cena_netto' => '34,90', 'stawka_vat' => '23'],
            4 => ['indeks' => 'ZAR-1', 'nazwa' => 'Żarówka', 'cena_netto' => '9,99', 'stawka_vat' => '23%'],
        ], $this->csv($csv));
    }

    public function testCsvWithoutRequiredColumnsIsRejectedAsAWhole(): void
    {
        $this->expectException(InvalidPriceList::class);
        $this->expectExceptionMessage('brakuje kolumn: cena_netto, stawka_vat');

        $this->csv("indeks;nazwa\nKAB-1;Kabel\n");
    }

    public function testEmptyCsvIsRejected(): void
    {
        $this->expectException(InvalidPriceList::class);

        $this->csv('');
    }

    public function testXmlReadsEveryProductIncludingNeighbours(): void
    {
        $rows = $this->xml('<?xml version="1.0"?><produkty>'
            .'<produkt><indeks>A</indeks><nazwa>Żarówka</nazwa><cena_netto>1</cena_netto><stawka_vat>23</stawka_vat></produkt>'
            .'<produkt><indeks>B</indeks><nazwa>B</nazwa><cena_netto>2</cena_netto><stawka_vat>8</stawka_vat><inne>x</inne></produkt>'
            ."\n<produkt><indeks>C</indeks><nazwa>C</nazwa><cena_netto>3</cena_netto><stawka_vat>5</stawka_vat></produkt>"
            .'</produkty>');

        self::assertSame(['A', 'B', 'C'], array_column($rows, 'indeks'));
        self::assertSame('Żarówka', $rows[0]['nazwa']);
        self::assertArrayNotHasKey('inne', $rows[1]);
    }

    public function testXmlEntitiesAreNotExpanded(): void
    {
        // An external entity would read a file of the server into the product name (XXE)
        $rows = $this->xml('<?xml version="1.0"?><!DOCTYPE p [<!ENTITY secret SYSTEM "file:///etc/hostname">]>'
            .'<produkty><produkt><indeks>A</indeks><nazwa>&secret;</nazwa><cena_netto>1</cena_netto><stawka_vat>23</stawka_vat></produkt></produkty>');

        self::assertSame('', $rows[0]['nazwa']);
    }

    public function testMalformedXmlIsRejectedWithItsLine(): void
    {
        $this->expectException(InvalidPriceList::class);
        $this->expectExceptionMessageMatches('/Błędny XML w wierszu \d+/');

        $this->xml("<?xml version=\"1.0\"?>\n<produkty>\n<produkt><indeks>A</indeks>\n</produkty>");
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function csv(string $content): array
    {
        return iterator_to_array((new PriceListReader())->rows($this->stream($content), PriceListFormat::Csv));
    }

    /**
     * @return list<array<string, string>>
     */
    private function xml(string $content): array
    {
        // Rows on the same line share a line number, so keys are dropped
        return iterator_to_array((new PriceListReader())->rows($this->stream($content), PriceListFormat::Xml), false);
    }

    /**
     * @return resource
     */
    private function stream(string $content)
    {
        $stream = fopen('php://memory', 'r+');
        \assert(false !== $stream);
        fwrite($stream, $content);
        rewind($stream);

        return $stream;
    }
}
