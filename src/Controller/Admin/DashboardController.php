<?php

namespace App\Controller\Admin;

use App\Entity\Order;
use App\Repository\CategoryRepository;
use App\Repository\InquiryRepository;
use App\Repository\OrderRepository;
use App\Repository\PlatformSettingRepository;
use App\Repository\ProductRepository;
use App\Repository\ProductVariantRepository;
use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class DashboardController extends AbstractController
{
    /** Length of the rolling window the KPI cards compare against the window before it. */
    private const KPI_DAYS = 30;

    #[Route('/admin', name: 'app_admin_dashboard', methods: ['GET'])]
    public function index(
        OrderRepository $orders,
        UserRepository $users,
        ProductRepository $products,
        ProductVariantRepository $variants,
        CategoryRepository $categories,
        InquiryRepository $inquiries,
        PlatformSettingRepository $settings,
    ): Response {
        $now = new \DateTimeImmutable();
        $periodStart = $now->modify(sprintf('-%d days', self::KPI_DAYS))->setTime(0, 0);
        $previousStart = $periodStart->modify(sprintf('-%d days', self::KPI_DAYS));
        $previousEnd = $periodStart->modify('-1 second');
        $monthStart = $now->modify('first day of this month')->setTime(0, 0);
        $yearStart = $now->modify('first day of january this year')->setTime(0, 0);
        $trendStart = $now->modify('-29 days')->setTime(0, 0);

        $hours = $settings->getInt('pending_order_escalation_hours', 24);
        $ordersByStatus = $orders->countByStatus();
        $lowStock = $variants->findLowStock();
        $outOfStock = $variants->findOutOfStock();
        $overdueOrders = $orders->findPendingOlderThan($hours);
        $revenueByCategory = $categories->revenueByCategory($periodStart, $now);

        return $this->render('admin/dashboard/index.html.twig', [
            'kpiDays' => self::KPI_DAYS,
            'kpis' => [
                'revenue' => $this->compare($orders->totalRevenueBetween($periodStart, $now), $orders->totalRevenueBetween($previousStart, $previousEnd)),
                'orders' => $this->compare((string) $orders->countBetween($periodStart, $now), (string) $orders->countBetween($previousStart, $previousEnd)),
                'averageOrderValue' => $this->compare($orders->averageOrderValue($periodStart, $now), $orders->averageOrderValue($previousStart, $previousEnd)),
                'newCustomers' => $this->compare((string) $users->countNewBetween($periodStart, $now), (string) $users->countNewBetween($previousStart, $previousEnd)),
                'cancellationRate' => $this->compareRate($orders->cancellationRate($periodStart, $now), $orders->cancellationRate($previousStart, $previousEnd)),
                'fulfillmentHours' => $this->compare($orders->averageFulfillmentHours($periodStart, $now), $orders->averageFulfillmentHours($previousStart, $previousEnd)),
            ],
            'operations' => [
                'pending' => $ordersByStatus[Order::STATUS_PENDING] ?? 0,
                'inProgress' => ($ordersByStatus[Order::STATUS_CONFIRMED] ?? 0) + ($ordersByStatus[Order::STATUS_PACKED] ?? 0) + ($ordersByStatus[Order::STATUS_SHIPPED] ?? 0),
                'overdue' => count($overdueOrders),
                'lowStock' => count($lowStock),
                'outOfStock' => count($outOfStock),
                'openInquiries' => $inquiries->countUnresolved(),
            ],
            'revenue' => [
                'Today' => $this->revenuePeriod($orders, $now->setTime(0, 0), $now, $now->modify('-1 day')->setTime(0, 0), $now->modify('-1 day')),
                'This week' => $this->revenuePeriod($orders, $now->modify('monday this week')->setTime(0, 0), $now, $now->modify('monday last week')->setTime(0, 0), $now->modify('-7 days')),
                'This month' => $this->revenuePeriod($orders, $monthStart, $now, $monthStart->modify('-1 month'), $now->modify('-1 month')),
                'This year' => $this->revenuePeriod($orders, $yearStart, $now, $yearStart->modify('-1 year'), $now->modify('-1 year')),
            ],
            'ordersByStatus' => $this->withShares($ordersByStatus),
            'escalationHours' => $hours,
            'overdueOrders' => $overdueOrders,
            'lowStock' => $lowStock,
            'outOfStock' => $outOfStock,
            'bestSellers' => $products->findBestSellers(5),
            'revenueByCategory' => $this->withBarWidths($revenueByCategory, 'revenue'),
            'salesTrend' => $this->dailyTrend($orders->salesByDay($trendStart, $now), $trendStart, $now),
            'inventoryValue' => $products->inventoryValue(),
            'openInquiries' => $inquiries->countUnresolved(),
        ]);
    }

    /**
     * @return array{value: string, previous: string, change: ?string} change is a percentage, null when the previous window was zero
     */
    private function compare(string $value, string $previous): array
    {
        $change = 0 === bccomp($previous, '0', 2)
            ? null
            : bcdiv(bcmul(bcsub($value, $previous, 2), '100', 4), $previous, 1);

        return ['value' => $value, 'previous' => $previous, 'change' => $change];
    }

    /**
     * Rates change in percentage points rather than percent of percent.
     *
     * @return array{value: string, previous: string, change: string}
     */
    private function compareRate(string $value, string $previous): array
    {
        return ['value' => $value, 'previous' => $previous, 'change' => bcsub($value, $previous, 1)];
    }

    /**
     * @return array{amount: string, change: ?string} change is null when the previous period had no revenue
     */
    private function revenuePeriod(OrderRepository $orders, \DateTimeImmutable $from, \DateTimeImmutable $to, \DateTimeImmutable $previousFrom, \DateTimeImmutable $previousTo): array
    {
        $amount = $orders->totalRevenueBetween($from, $to);
        $previous = $orders->totalRevenueBetween($previousFrom, $previousTo);

        $change = 0 === bccomp($previous, '0', 2)
            ? null
            : bcdiv(bcmul(bcsub($amount, $previous, 2), '100', 4), $previous, 2);

        return ['amount' => $amount, 'change' => $change];
    }

    /**
     * One row per day, zero-filled, with a bar height relative to the best day.
     *
     * @param list<array{day: string, revenue: string, orders: int|string}> $rows
     *
     * @return list<array{day: string, revenue: string, orders: int, height: string}>
     */
    private function dailyTrend(array $rows, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $byDay = [];
        foreach ($rows as $row) {
            $byDay[$row['day']] = $row;
        }

        $days = [];
        for ($day = $from; $day <= $to; $day = $day->modify('+1 day')) {
            $key = $day->format('Y-m-d');
            $days[] = ['day' => $key, 'revenue' => (string) ($byDay[$key]['revenue'] ?? '0.00'), 'orders' => (int) ($byDay[$key]['orders'] ?? 0)];
        }

        return $this->withBarWidths($days, 'revenue', 'height');
    }

    /**
     * Adds a 0-100 bar size to each row, relative to the largest value, without going through floats.
     *
     * @template T of array<string, mixed>
     *
     * @param list<T> $rows
     *
     * @return list<T>
     */
    private function withBarWidths(array $rows, string $field, string $key = 'width'): array
    {
        $max = '0';
        foreach ($rows as $row) {
            if (1 === bccomp((string) $row[$field], $max, 2)) {
                $max = (string) $row[$field];
            }
        }

        foreach ($rows as $i => $row) {
            $rows[$i][$key] = 0 === bccomp($max, '0', 2) ? '0' : bcdiv(bcmul((string) $row[$field], '100', 2), $max, 0);
        }

        return $rows;
    }

    /**
     * @param array<string, int> $counts
     *
     * @return list<array{status: string, count: int, share: string}>
     */
    private function withShares(array $counts): array
    {
        $total = array_sum($counts);
        $rows = [];
        foreach ($counts as $status => $count) {
            $rows[] = ['status' => $status, 'count' => $count, 'share' => 0 === $total ? '0' : bcdiv(bcmul((string) $count, '100', 2), (string) $total, 1)];
        }

        return $rows;
    }
}
