<?php

namespace App\Invoicing\Service;

use App\Invoicing\Entity\Invoice;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Where the generated PDF files are kept on disk.
 */
final class InvoicePdfStorage
{
    public function __construct(
        #[Autowire(param: 'app.invoice_dir')]
        private readonly string $directory,
        private readonly Filesystem $filesystem,
    ) {
    }

    public function path(Invoice $invoice): string
    {
        return \sprintf('%s/%d.pdf', $this->directory, (int) $invoice->getId());
    }

    public function exists(Invoice $invoice): bool
    {
        return is_file($this->path($invoice));
    }

    public function write(Invoice $invoice, string $content): void
    {
        // dumpFile writes to a temporary file and renames it, so a half-written PDF is never served
        $this->filesystem->dumpFile($this->path($invoice), $content);
    }
}
