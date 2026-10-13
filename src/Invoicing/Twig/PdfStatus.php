<?php

namespace App\Invoicing\Twig;

use App\Invoicing\Entity\Invoice;
use App\Invoicing\Entity\PdfStatus as Status;
use App\Invoicing\Service\InvoicePdfStorage;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * Shows whether the PDF of an invoice is ready. While the background worker is still at it,
 * the component asks the server again every moment (data-poll) and turns into a download
 * button by itself once the file exists.
 */
#[AsLiveComponent(name: 'Invoicing:PdfStatus', template: 'invoicing/_pdf_status.html.twig')]
final class PdfStatus
{
    use DefaultActionTrait;

    /** Reloaded from the database on every poll, so the status is always current. */
    #[LiveProp]
    public Invoice $invoice;

    public function __construct(private readonly InvoicePdfStorage $storage)
    {
    }

    public function isReady(): bool
    {
        return Status::Ready === $this->invoice->getPdfStatus() && $this->storage->exists($this->invoice);
    }

    public function isFailed(): bool
    {
        return Status::Failed === $this->invoice->getPdfStatus();
    }
}
