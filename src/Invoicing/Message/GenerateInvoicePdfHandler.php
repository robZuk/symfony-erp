<?php

namespace App\Invoicing\Message;

use App\Invoicing\Entity\PdfStatus;
use App\Invoicing\Repository\InvoiceRepository;
use App\Invoicing\Service\InvoicePdfRenderer;
use App\Invoicing\Service\InvoicePdfStorage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

/**
 * Runs in the background worker (`messenger:consume async`), not during the web request.
 */
#[AsMessageHandler]
final class GenerateInvoicePdfHandler
{
    public function __construct(
        private readonly InvoiceRepository $invoices,
        private readonly InvoicePdfRenderer $renderer,
        private readonly InvoicePdfStorage $storage,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(GenerateInvoicePdf $message): void
    {
        $invoice = $this->invoices->find($message->invoiceId)
            // Retrying will not make a missing invoice appear
            ?? throw new UnrecoverableMessageHandlingException(\sprintf('Invoice %d does not exist.', $message->invoiceId));

        try {
            $this->storage->write($invoice, $this->renderer->render($invoice));
            $invoice->setPdfStatus(PdfStatus::Ready);
        } catch (\Throwable $exception) {
            $invoice->setPdfStatus(PdfStatus::Failed);

            // Rethrown so Messenger retries; a later successful attempt sets the status back to ready
            throw $exception;
        } finally {
            $this->entityManager->flush();
        }
    }
}
