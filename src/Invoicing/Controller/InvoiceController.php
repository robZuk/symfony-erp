<?php

namespace App\Invoicing\Controller;

use App\Invoicing\Dto\InvoiceFilter;
use App\Invoicing\Dto\IssueInvoice;
use App\Invoicing\Entity\Invoice;
use App\Invoicing\Entity\PdfStatus;
use App\Invoicing\Export\SalesRegisterWriter;
use App\Invoicing\Form\CorrectInvoiceType;
use App\Invoicing\Form\IssueInvoiceType;
use App\Invoicing\Repository\InvoiceRepository;
use App\Invoicing\Service\InvoiceIssuing;
use App\Invoicing\Service\InvoicePdfStorage;
use App\Shared\Sales\BillableOrders;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/invoicing/invoices', name: 'invoicing_invoice_')]
final class InvoiceController extends AbstractController
{
    public function __construct(
        private readonly InvoiceRepository $invoices,
        private readonly InvoiceIssuing $issuing,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(#[MapQueryString] InvoiceFilter $filter = new InvoiceFilter()): Response
    {
        return $this->render('invoicing/index.html.twig', [
            'filter' => $filter,
            'page' => $this->invoices->search($filter),
        ]);
    }

    /**
     * Issues the invoice for a shipped order: /invoicing/invoices/new?order=12.
     */
    /**
     * The sales register of a month as CSV for the accounting office (?month=2026-10, the current month
     * by default). Streamed: rows are written while the response is sent.
     */
    #[Route('/register.csv', name: 'register', methods: ['GET'])]
    public function register(
        SalesRegisterWriter $writer,
        #[MapQueryParameter(filter: \FILTER_VALIDATE_REGEXP, options: ['regexp' => '/^\d{4}-(0[1-9]|1[0-2])$/'])] ?string $month = null,
    ): StreamedResponse {
        $start = null === $month ? $this->clock->now() : new \DateTimeImmutable($month.'-01');
        $invoices = $this->invoices;

        return new StreamedResponse(
            static function () use ($writer, $invoices, $start): void {
                $out = fopen('php://output', 'w') ?: throw new \RuntimeException('Cannot write the response.');
                $writer->write($invoices->issuedIn($start), $out);
                fclose($out);
            },
            Response::HTTP_OK,
            [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, \sprintf('rejestr-sprzedazy-%s.csv', $start->format('Y-m'))),
            ],
        );
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_INVOICING_EDIT')]
    public function new(Request $request, BillableOrders $orders, #[MapQueryParameter(options: ['min_range' => 1])] int $order): Response
    {
        $billable = $orders->find($order) ?? throw $this->createNotFoundException();
        if (!$billable->billable) {
            $this->addFlash('error', 'Fakturę można wystawić tylko do wydanego, niezafakturowanego zamówienia.');

            return $this->redirectToRoute('sales_order_show', ['id' => $order], Response::HTTP_SEE_OTHER);
        }

        $data = new IssueInvoice($this->clock->now());
        $form = $this->createForm(IssueInvoiceType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid() && null !== $data->issueDate && null !== $data->paymentDays && null !== $data->paymentMethod) {
            try {
                $invoice = $this->issuing->issueForOrder($order, $data->issueDate, $data->paymentDays, $data->paymentMethod);
                $this->addFlash('success', \sprintf('Wystawiono fakturę %s.', $invoice->getNumber()));

                return $this->redirectToShow($invoice);
            } catch (\DomainException $exception) {
                $this->addFlash('error', $exception->getMessage());

                return $this->redirectToRoute('sales_order_show', ['id' => $order], Response::HTTP_SEE_OTHER);
            }
        }

        return $this->render('invoicing/new.html.twig', [
            'form' => $form,
            'order' => $billable,
        ]);
    }

    #[Route('/{id}', name: 'show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(Invoice $invoice): Response
    {
        return $this->render('invoicing/show.html.twig', ['invoice' => $invoice]);
    }

    #[Route('/{id}/correct', name: 'correct', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_INVOICING_EDIT')]
    public function correct(Request $request, Invoice $invoice): Response
    {
        if ($invoice->isCorrection()) {
            $this->addFlash('error', 'Faktury korygującej nie można skorygować. Wystaw kolejną korektę do faktury pierwotnej.');

            return $this->redirectToShow($invoice);
        }

        $form = $this->createForm(CorrectInvoiceType::class, ['issueDate' => $this->clock->now()->setTime(0, 0)], ['invoice' => $invoice]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{issueDate: \DateTimeImmutable, reason: string, quantities: array<int, int>} $data */
            $data = $form->getData();

            try {
                $correction = $this->issuing->correct($invoice, $data['quantities'], $data['reason'], $data['issueDate']);
                $this->addFlash('success', \sprintf('Wystawiono fakturę korygującą %s.', $correction->getNumber()));

                return $this->redirectToShow($correction);
            } catch (\DomainException $exception) {
                $this->addFlash('error', $exception->getMessage());

                return $this->redirectToRoute('invoicing_invoice_correct', ['id' => $invoice->getId()], Response::HTTP_SEE_OTHER);
            }
        }

        return $this->render('invoicing/correct.html.twig', [
            'form' => $form,
            'invoice' => $invoice,
        ]);
    }

    #[Route('/{id}/pdf', name: 'pdf', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function pdf(Invoice $invoice, InvoicePdfStorage $storage): Response
    {
        if (!$storage->exists($invoice)) {
            throw $this->createNotFoundException('The PDF has not been generated yet.');
        }

        $response = new BinaryFileResponse($storage->path($invoice));
        $response->headers->set('Content-Type', 'application/pdf');
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(
            HeaderUtils::DISPOSITION_INLINE,
            str_replace('/', '-', $invoice->getNumber()).'.pdf',
        ));

        return $response;
    }

    #[Route('/{id}/pdf/retry', name: 'pdf_retry', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_INVOICING_EDIT')]
    #[IsCsrfTokenValid('invoicing_pdf_retry')]
    public function retryPdf(Invoice $invoice, EntityManagerInterface $entityManager): Response
    {
        $invoice->setPdfStatus(PdfStatus::Pending);
        $entityManager->flush();
        $this->issuing->queuePdf($invoice);

        return $this->redirectToShow($invoice);
    }

    private function redirectToShow(Invoice $invoice): Response
    {
        return $this->redirectToRoute('invoicing_invoice_show', ['id' => $invoice->getId()], Response::HTTP_SEE_OTHER);
    }
}
