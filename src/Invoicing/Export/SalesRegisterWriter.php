<?php

namespace App\Invoicing\Export;

use App\Invoicing\Entity\Invoice;
use App\Shared\Money\Money;
use App\Shared\Tax\VatRate;

/**
 * The sales register of a month as CSV, for the accounting office: one row per invoice or
 * correction, with net and VAT for every rate. A correction carries the change it makes (usually
 * negative) and names the invoice it corrects, so the sum of a column is the month's figure,
 * to the grosz.
 *
 * Written for a Polish spreadsheet: UTF-8 with a byte order mark, semicolons, decimal commas.
 */
final class SalesRegisterWriter
{
    /** Columns of the VAT rates, in the order they appear in the file. */
    private const array RATES = [VatRate::Standard, VatRate::Reduced8, VatRate::Reduced5, VatRate::Zero, VatRate::Exempt];

    /**
     * @param iterable<Invoice> $invoices
     * @param resource          $out
     */
    public function write(iterable $invoices, $out): void
    {
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, self::header(), ';', '"', '');

        foreach ($invoices as $invoice) {
            $byRate = [];
            foreach ($invoice->totals()->rows as $row) {
                $byRate[$row->rate->value] = $row;
            }

            $amounts = [];
            foreach (self::RATES as $rate) {
                $amounts[] = self::decimal(($byRate[$rate->value] ?? null)?->net);
                // Zero-rated and exempt sales have no VAT column
                if (self::hasVat($rate)) {
                    $amounts[] = self::decimal(($byRate[$rate->value] ?? null)?->vat);
                }
            }

            fputcsv($out, [
                $invoice->getNumber(),
                $invoice->isCorrection() ? 'korekta' : 'faktura',
                $invoice->getCorrected()?->getNumber() ?? '',
                $invoice->getIssueDate()->format('Y-m-d'),
                $invoice->getSaleDate()->format('Y-m-d'),
                $invoice->getBuyer()->name,
                $invoice->getBuyer()->nip ?? '',
                ...$amounts,
                self::decimal($invoice->getNetTotal()),
                self::decimal($invoice->getVatTotal()),
                self::decimal($invoice->getGrossTotal()),
            ], ';', '"', '');
        }
    }

    /**
     * @return list<string>
     */
    private static function header(): array
    {
        $columns = ['numer', 'rodzaj', 'korygowana_faktura', 'data_wystawienia', 'data_sprzedazy', 'nabywca', 'nip_nabywcy'];
        foreach (self::RATES as $rate) {
            $columns[] = 'netto_'.$rate->value;
            if (self::hasVat($rate)) {
                $columns[] = 'vat_'.$rate->value;
            }
        }

        return [...$columns, 'netto', 'vat', 'brutto'];
    }

    private static function hasVat(VatRate $rate): bool
    {
        return VatRate::Zero !== $rate && VatRate::Exempt !== $rate;
    }

    /**
     * -4990 grosze => "-49,90"; empty for a rate the invoice does not use.
     */
    private static function decimal(?Money $money): string
    {
        if (null === $money) {
            return '';
        }

        $minor = $money->minor;

        return \sprintf('%s%d,%02d', $minor < 0 ? '-' : '', intdiv(abs($minor), 100), abs($minor) % 100);
    }
}
