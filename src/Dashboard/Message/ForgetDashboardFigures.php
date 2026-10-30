<?php

namespace App\Dashboard\Message;

use App\Dashboard\Service\DashboardReport;
use App\Shared\Event\InvoiceIssued;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Contracts\Cache\TagAwareCacheInterface;

/**
 * Subscriber of the RabbitMQ queue "erp.dashboard" (`messenger:consume events`): a new invoice
 * changes the sales figures, so the cached ones are dropped and the next visit computes them afresh.
 *
 * Dropping a cache entry twice does no harm, so an event delivered twice is fine.
 */
#[AsMessageHandler(fromTransport: 'events')]
final class ForgetDashboardFigures
{
    public function __construct(
        #[Target('dashboard.cache')]
        private readonly TagAwareCacheInterface $cache,
    ) {
    }

    public function __invoke(InvoiceIssued $event): void
    {
        $this->cache->invalidateTags([DashboardReport::SALES_TAG]);
    }
}
