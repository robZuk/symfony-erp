<?php

namespace App\Catalog\Message;

use App\Catalog\Exchange\ProductImporter;
use App\Catalog\Repository\ProductImportRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

/**
 * Runs in the background worker (`messenger:consume async`): a big price list takes a while,
 * longer than a web request should.
 */
#[AsMessageHandler]
final class ImportProductsHandler
{
    public function __construct(
        private readonly ProductImportRepository $imports,
        private readonly ProductImporter $importer,
    ) {
    }

    public function __invoke(ImportProducts $message): void
    {
        $import = $this->imports->find($message->importId)
            // Retrying will not make a missing import appear
            ?? throw new UnrecoverableMessageHandlingException(\sprintf('Product import %d does not exist.', $message->importId));

        $this->importer->run($import);
    }
}
