<?php

namespace App\Tests\Catalog;

use App\Catalog\Entity\Product;
use App\Catalog\Entity\ProductKind;
use App\Catalog\Entity\UnitOfMeasure;
use App\Catalog\Exchange\ImportProblem;
use App\Catalog\Exchange\PriceListRow;
use App\Shared\Tax\VatRate;
use PHPUnit\Framework\TestCase;

final class PriceListRowTest extends TestCase
{
    public function testValuesAsPeopleTypeThem(): void
    {
        $row = $this->row(['indeks' => ' kab-1 ', 'nazwa' => 'Kabel', 'cena_netto' => '1 234,5', 'stawka_vat' => '8%', 'rodzaj' => 'Usługa', 'jednostka' => 'godz', 'stan_minimalny' => '2,5', 'aktywny' => 'NIE']);

        self::assertSame('KAB-1', $row->sku);
        self::assertSame(123450, $row->netPrice);
        self::assertSame(VatRate::Reduced8, $row->vatRate);
        self::assertSame(ProductKind::Service, $row->kind);
        self::assertSame(UnitOfMeasure::Hour, $row->unit);
        self::assertSame(2500, $row->minimumStock);
        self::assertFalse($row->active);
    }

    public function testEmptyOptionalColumnsKeepWhatTheProductHas(): void
    {
        $product = new Product();
        $product->setKind(ProductKind::Service);
        $product->setUnit(UnitOfMeasure::Hour);
        $product->setMinimumStock(5000);
        $product->setActive(false);

        $this->row(['indeks' => 'A', 'nazwa' => 'Nowa nazwa', 'cena_netto' => '10', 'stawka_vat' => 'zw.', 'rodzaj' => '', 'jednostka' => ''])->applyTo($product);

        self::assertSame('Nowa nazwa', $product->getName());
        self::assertSame(1000, $product->getNetPrice());
        self::assertSame(VatRate::Exempt, $product->getVatRate());
        self::assertSame(ProductKind::Service, $product->getKind());
        self::assertSame(UnitOfMeasure::Hour, $product->getUnit());
        self::assertSame(5000, $product->getMinimumStock());
        self::assertFalse($product->isActive());
    }

    public function testEveryProblemOfTheRowIsReported(): void
    {
        $problems = PriceListRow::parse(7, ['indeks' => '', 'nazwa' => '', 'cena_netto' => '-5', 'stawka_vat' => '7', 'rodzaj' => 'meble', 'jednostka' => 'beczka', 'stan_minimalny' => 'dużo', 'aktywny' => 'może']);

        self::assertIsArray($problems);
        self::assertSame(
            ['indeks', 'nazwa', 'cena_netto', 'stawka_vat', 'rodzaj', 'jednostka', 'stan_minimalny', 'aktywny'],
            array_map(static fn (ImportProblem $problem): ?string => $problem->column, $problems),
        );
        self::assertSame([7], array_values(array_unique(array_map(static fn (ImportProblem $problem): ?int => $problem->line, $problems))));
    }

    public function testPriceWithMoreThanTwoDecimalsIsNotRounded(): void
    {
        self::assertIsArray(PriceListRow::parse(2, ['indeks' => 'A', 'nazwa' => 'A', 'cena_netto' => '9,999', 'stawka_vat' => '23']));
    }

    /**
     * @param array<string, string> $raw
     */
    private function row(array $raw): PriceListRow
    {
        $row = PriceListRow::parse(2, $raw);
        self::assertInstanceOf(PriceListRow::class, $row);

        return $row;
    }
}
