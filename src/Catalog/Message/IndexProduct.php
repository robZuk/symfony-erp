<?php

namespace App\Catalog\Message;

use App\Catalog\Repository\ProductRepository;
use App\Catalog\Search\ProductIndex;
use App\Shared\Event\ProductChanged;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Subscriber of the RabbitMQ queue "erp.search" (`messenger:consume events`): brings the search
 * entry of a product in line with the database.
 *
 * It writes what the database holds now, not what the event says, so an event that comes late,
 * twice or after a newer one does no harm. If Elasticsearch is down the handler fails and the
 * event is retried; a product saved meanwhile is still found through the database fallback.
 *
 * Where Elasticsearch is switched off (ELASTICSEARCH_ENABLED) there is no index to keep up to date:
 * the event is skipped, or it would end in the "failed" transport after its retries.
 * app:search:reindex brings the index up to date when it is switched on.
 */
#[AsMessageHandler(fromTransport: 'events')]
final class IndexProduct
{
    public function __construct(
        private readonly ProductRepository $products,
        private readonly ProductIndex $index,
        #[Autowire('%env(bool:ELASTICSEARCH_ENABLED)%')]
        private readonly bool $enabled,
    ) {
    }

    public function __invoke(ProductChanged $event): void
    {
        if (!$this->enabled) {
            return;
        }

        $product = $this->products->find($event->productId);

        if (null === $product) {
            $this->index->remove($event->productId);

            return;
        }

        $this->index->index($product);
    }
}
