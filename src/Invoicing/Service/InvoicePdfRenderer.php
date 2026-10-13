<?php

namespace App\Invoicing\Service;

use App\Invoicing\Entity\Invoice;
use Dompdf\Dompdf;
use Dompdf\Options;
use Twig\Environment;

/**
 * Turns an invoice into a PDF document by rendering templates/invoicing/pdf.html.twig.
 */
final class InvoicePdfRenderer
{
    public function __construct(private readonly Environment $twig)
    {
    }

    /**
     * @return string the binary content of the PDF file
     */
    public function render(Invoice $invoice): string
    {
        $options = new Options();
        // DejaVu Sans ships with dompdf and has the Polish letters
        $options->setDefaultFont('DejaVu Sans');
        // The template is self-contained: nothing is fetched from disk or the network
        $options->setIsRemoteEnabled(false);

        $dompdf = new Dompdf($options);
        $dompdf->setPaper('A4');
        $dompdf->loadHtml($this->twig->render('invoicing/pdf.html.twig', ['invoice' => $invoice]), 'UTF-8');
        $dompdf->render();

        return (string) $dompdf->output();
    }
}
