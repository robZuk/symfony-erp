<?php

namespace App\Dashboard\Service;

use App\Inventory\Repository\StockLevelRepository;
use App\Invoicing\Repository\InvoiceRepository;
use App\Sales\Entity\OrderStatus;
use App\Sales\Entity\SalesOrder;
use App\Sales\Repository\SalesOrderRepository;
use App\Shared\Catalog\ProductDirectory;
use App\Shared\Catalog\ProductSummary;
use App\Shared\Money\Money;
use App\Shared\Partner\PartnerDirectory;
use Psr\Clock\ClockInterface;

/**
 * The numbers behind the home page. This is the one place that reads from every module.
 */
final class DashboardReport
{
    private const int MONTHS = 6;

    public function __construct(
        private readonly InvoiceRepository $invoices,
        private readonly SalesOrderRepository $orders,
        private readonly StockLevelRepository $stockLevels,
        private readonly ProductDirectory $products,
        private readonly PartnerDirectory $partners,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * Net sales (invoices and their corrections) for the last six months, oldest first.
     *
     * @return list<array{month: \DateTimeImmutable, net: Money}>
     */
    public function monthlySales(): array
    {
        $thisMonth = $this->clock->now()->modify('first day of this month')->setTime(0, 0);
        $from = $thisMonth->modify(\sprintf('-%d months', self::MONTHS - 1));
        $netByMonth = $this->invoices->netByMonthSince($from);

        $sales = [];
        for ($month = $from; $month <= $thisMonth; $month = $month->modify('+1 month')) {
            $sales[] = ['month' => $month, 'net' => Money::ofMinor($netByMonth[$month->format('Y-m')] ?? 0)];
        }

        return $sales;
    }

    /**
     * @return array{toShip: int, toInvoice: int, drafts: int}
     */
    public function orderQueue(): array
    {
        $counts = $this->orders->countByStatus();

        return [
            'toShip' => $counts[OrderStatus::Confirmed->value] ?? 0,
            'toInvoice' => $counts[OrderStatus::Shipped->value] ?? 0,
            'drafts' => $counts[OrderStatus::Draft->value] ?? 0,
        ];
    }

    /**
     * Watched products whose available quantity has dropped below their minimum, most urgent first.
     *
     * @return list<array{product: ProductSummary, available: int}>
     */
    public function lowStock(): array
    {
        $watched = $this->products->watched();
        $available = $this->stockLevels->availableByProduct(array_map(static fn (ProductSummary $product): int => $product->id, $watched));

        $low = [];
        foreach ($watched as $product) {
            if (($available[$product->id] ?? 0) < $product->minimumStock) {
                $low[] = ['product' => $product, 'available' => $available[$product->id] ?? 0];
            }
        }

        // The smaller the share of the minimum that is left, the more urgent
        usort($low, static fn (array $a, array $b): int => $a['available'] / $a['product']->minimumStock <=> $b['available'] / $b['product']->minimumStock);

        return $low;
    }

    /**
     * @return list<array{order: SalesOrder, customer: string}>
     */
    public function latestOrders(int $limit = 6): array
    {
        $orders = $this->orders->latest($limit);
        $customers = $this->partners->findMany(array_map(static fn (SalesOrder $order): int => $order->getCustomerId(), $orders));

        return array_map(
            static fn (SalesOrder $order): array => ['order' => $order, 'customer' => isset($customers[$order->getCustomerId()]) ? $customers[$order->getCustomerId()]->name : '—'],
            $orders,
        );
    }
}
