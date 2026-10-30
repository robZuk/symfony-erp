<?php

namespace App\Shared\Event;

/**
 * A VAT invoice or a correcting invoice was issued. The totals of a correction are the change
 * it makes, so they are usually negative, and adding up the events gives the sales figure.
 */
final readonly class InvoiceIssued implements IntegrationEvent
{
    public function __construct(
        public int $invoiceId,
        public string $number,
        public bool $correction,
        public int $orderId,
        /** In grosze. */
        public int $netTotal,
        /** In grosze. */
        public int $grossTotal,
        public \DateTimeImmutable $issueDate,
        public \DateTimeImmutable $occurredAt,
    ) {
    }

    public static function routingKey(): string
    {
        return 'invoicing.invoice.issued';
    }
}
