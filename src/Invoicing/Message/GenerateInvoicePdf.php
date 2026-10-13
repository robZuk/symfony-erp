<?php

namespace App\Invoicing\Message;

/**
 * Asks the background worker to render the PDF file of an invoice.
 * Carries only the id: the handler loads the invoice as it is when the work starts.
 */
final readonly class GenerateInvoicePdf
{
    public function __construct(public int $invoiceId)
    {
    }
}
