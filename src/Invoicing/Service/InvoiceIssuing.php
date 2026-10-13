<?php

namespace App\Invoicing\Service;

use App\Invoicing\Entity\Invoice;
use App\Invoicing\Entity\InvoiceType;
use App\Invoicing\Entity\Party;
use App\Invoicing\Entity\PaymentMethod;
use App\Invoicing\Message\GenerateInvoicePdf;
use App\Shared\Audit\ActivityLog;
use App\Shared\Catalog\ProductDirectory;
use App\Shared\Numbering\DocumentNumberGenerator;
use App\Shared\Partner\PartnerDirectory;
use App\Shared\Persistence\Transactional;
use App\Shared\Sales\BillableOrders;
use App\Shared\Tax\VatBreakdown;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Issues invoices and corrections. Each method is one transaction that numbers the document,
 * saves it, updates the order and queues the PDF; if any part fails, none of it happens.
 */
final class InvoiceIssuing
{
    public function __construct(
        private readonly Transactional $transactional,
        private readonly EntityManagerInterface $entityManager,
        private readonly BillableOrders $orders,
        private readonly PartnerDirectory $partners,
        private readonly ProductDirectory $products,
        private readonly SellerProvider $seller,
        private readonly DocumentNumberGenerator $numbers,
        private readonly MessageBusInterface $bus,
        private readonly ClockInterface $clock,
        private readonly ActivityLog $activity,
    ) {
    }

    /**
     * @param int $paymentDays days from the issue date until payment is due
     *
     * @throws \DomainException when the order cannot be invoiced
     */
    public function issueForOrder(int $orderId, \DateTimeImmutable $issueDate, int $paymentDays, PaymentMethod $paymentMethod): Invoice
    {
        return $this->transactional->run(function () use ($orderId, $issueDate, $paymentDays, $paymentMethod): Invoice {
            $order = $this->orders->find($orderId) ?? throw new \DomainException('Zamówienie nie istnieje.');
            if (!$order->billable || [] === $order->lines) {
                throw new \DomainException('Fakturę można wystawić tylko do wydanego, niezafakturowanego zamówienia.');
            }

            $customer = $this->partners->find($order->customerId) ?? throw new \DomainException('Odbiorca zamówienia nie istnieje.');
            $products = $this->products->findMany(array_map(static fn ($line): int => $line->productId, $order->lines));

            $invoice = new Invoice(
                InvoiceType::Invoice,
                $this->numbers->next(InvoiceType::Invoice->numberSeries(), $issueDate),
                $order->id,
                $order->number,
                $this->seller->party(),
                $this->seller->bankAccount(),
                new Party($customer->name, $customer->nip, $customer->street, $customer->postalCode, $customer->city, $customer->countryCode),
                $issueDate,
                $order->shippedAt ?? $issueDate,
                $issueDate->modify(\sprintf('+%d days', max(0, $paymentDays))),
                $paymentMethod,
                $this->clock->now(),
            );

            foreach ($order->lines as $line) {
                $product = $products[$line->productId] ?? throw new \DomainException('Produkt z zamówienia nie istnieje.');
                $invoice->addLine(
                    $product->id,
                    $product->sku,
                    $product->name,
                    $product->unit,
                    $line->quantity,
                    $line->unitNetPrice,
                    $line->vatRate,
                    $line->unitNetPrice->timesQuantity($line->quantity),
                );
            }

            $invoice->close(VatBreakdown::ofLines($invoice->lineValues()));

            $this->entityManager->persist($invoice);
            $this->entityManager->flush();

            // Refuses, and so undoes everything above, if the order was invoiced in the meantime
            $this->orders->markInvoiced($order->id, (int) $invoice->getId(), $invoice->getNumber());
            $this->activity->record('invoicing.invoice.issued', \sprintf('Wystawiono fakturę %s dla %s.', $invoice->getNumber(), $invoice->getBuyer()->name), 'invoicing_invoice_show', ['id' => (int) $invoice->getId()]);
            $this->queuePdf($invoice);

            return $invoice;
        });
    }

    /**
     * Issues a correcting invoice that lowers the quantities of some lines.
     *
     * @param array<int, int> $newQuantities line id of the original invoice => quantity it should have, in thousandths;
     *                                       lines that are left out stay as they are
     *
     * @throws \DomainException when the correction is not possible
     */
    public function correct(Invoice $original, array $newQuantities, string $reason, \DateTimeImmutable $issueDate): Invoice
    {
        return $this->transactional->run(function () use ($original, $newQuantities, $reason, $issueDate): Invoice {
            if ($original->isCorrection()) {
                throw new \DomainException('Faktury korygującej nie można skorygować. Wystaw kolejną korektę do faktury pierwotnej.');
            }
            if ('' === trim($reason)) {
                throw new \DomainException('Podaj przyczynę korekty.');
            }

            // Two corrections of one invoice are issued one after the other, each seeing the previous one
            $this->entityManager->refresh($original, LockMode::PESSIMISTIC_WRITE);

            $correction = new Invoice(
                InvoiceType::Correction,
                $this->numbers->next(InvoiceType::Correction->numberSeries(), $issueDate),
                $original->getOrderId(),
                $original->getOrderNumber(),
                $original->getSeller(),
                $original->getSellerBankAccount(),
                $original->getBuyer(),
                $issueDate,
                $original->getSaleDate(),
                $issueDate,
                $original->getPaymentMethod(),
                $this->clock->now(),
                $original,
                trim($reason),
            );

            // Net value of every line of the original as it stands now, and as it will stand after this correction
            $before = [];
            $after = [];

            foreach ($original->getLines() as $line) {
                $current = $original->currentQuantity($line);
                $new = $newQuantities[(int) $line->getId()] ?? $current;
                $before[] = [$line->getUnitNetPrice()->timesQuantity($current), $line->getVatRate()];
                $after[] = [$line->getUnitNetPrice()->timesQuantity(max(0, $new)), $line->getVatRate()];

                if ($new < 0 || $new > $current) {
                    throw new \DomainException(\sprintf('Pozycja %s: ilość po korekcie musi być między zerem a ilością dotychczasową.', $line->getSku()));
                }
                if ($new === $current) {
                    continue;
                }

                $price = $line->getUnitNetPrice();
                $correction->addLine(
                    $line->getProductId(),
                    $line->getSku(),
                    $line->getName(),
                    $line->getUnit(),
                    $new - $current,
                    $price,
                    $line->getVatRate(),
                    // The difference between two rounded values, so "before" plus "difference" is exactly "after"
                    $price->timesQuantity($new)->minus($price->timesQuantity($current)),
                    $current,
                    $line,
                );
            }

            if ($correction->getLines()->isEmpty()) {
                throw new \DomainException('Korekta niczego nie zmienia.');
            }

            // "After" minus "before": the original and all its corrections always add up to the grosz
            $correction->close(VatBreakdown::ofLines($after)->minus(VatBreakdown::ofLines($before)));

            $this->entityManager->persist($correction);
            $this->entityManager->flush();
            $this->activity->record('invoicing.invoice.corrected', \sprintf('Wystawiono korektę %s do faktury %s.', $correction->getNumber(), $original->getNumber()), 'invoicing_invoice_show', ['id' => (int) $correction->getId()]);
            $this->queuePdf($correction);

            return $correction;
        });
    }

    /**
     * Queues the PDF again, e.g. after a failed attempt.
     */
    public function queuePdf(Invoice $invoice): void
    {
        // With the "doctrine" transport the message is a row in the same database, written in the same
        // transaction as the invoice: the worker can never see a message for an invoice that was rolled back
        $this->bus->dispatch(new GenerateInvoicePdf((int) $invoice->getId()));
    }
}
