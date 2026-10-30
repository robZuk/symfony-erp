<?php

namespace App\Catalog\Command;

use App\Catalog\Repository\ProductRepository;
use App\Catalog\Search\ProductIndex;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Rebuilds the product search index from the database, without interrupting search.
 *
 * Needed once after the index is first set up (an empty Elasticsearch), after a change of
 * ProductIndex::definition(), and whenever the index is suspected to have drifted from the database.
 * Day-to-day changes reach the index through ProductChanged events.
 */
#[AsCommand(name: 'app:search:reindex', description: 'Rebuilds the product search index from the database')]
final class ReindexProducts
{
    public function __construct(
        private readonly ProductIndex $index,
        private readonly ProductRepository $products,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Do nothing when the index already exists; safe to run on every deployment')] bool $ifMissing = false,
    ): int {
        if ($ifMissing && $this->index->exists()) {
            $io->success(\sprintf('The index "%s" already exists.', $this->index->alias()));

            return Command::SUCCESS;
        }

        $count = $this->index->rebuild($this->products->streamAll());
        $io->success(\sprintf('Indexed %d products in "%s".', $count, $this->index->alias()));

        return Command::SUCCESS;
    }
}
