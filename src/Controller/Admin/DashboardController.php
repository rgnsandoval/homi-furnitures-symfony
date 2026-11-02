<?php

namespace App\Controller\Admin;

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
        $monthStart = $now->modify('first day of this month')->setTime(0, 0);
        $yearStart = $now->modify('first day of january this year')->setTime(0, 0);
        $trendStart = $now->modify('-29 days')->setTime(0, 0);

        return $this->render('admin/dashboard/index.html.twig', [
            'revenue' => [
                'Today' => $this->revenuePeriod($orders, $now->setTime(0, 0), $now, $now->modify('-1 day')->setTime(0, 0), $now->modify('-1 day')),
                'This week' => $this->revenuePeriod($orders, $now->modify('monday this week')->setTime(0, 0), $now, $now->modify('monday last week')->setTime(0, 0), $now->modify('-7 days')),
                'This month' => $this->revenuePeriod($orders, $monthStart, $now, $monthStart->modify('-1 month'), $now->modify('-1 month')),
                'This year' => $this->revenuePeriod($orders, $yearStart, $now, $yearStart->modify('-1 year'), $now->modify('-1 year')),
            ],
            'ordersByStatus' => $orders->countByStatus(),
            'averageOrderValue' => $orders->averageOrderValue($monthStart, $now),
            'newUsers' => $users->countNewBetween($monthStart, $now),
            'lowStock' => $variants->findLowStock(),
            'outOfStock' => $variants->findOutOfStock(),
            'escalationHours' => $hours = $settings->getInt('pending_order_escalation_hours', 24),
            'overdueOrders' => $orders->findPendingOlderThan($hours),
            'bestSellers' => $products->findBestSellers(5),
            'revenueByCategory' => $categories->revenueByCategory($monthStart, $now),
            'cancellationRate' => $orders->cancellationRate($monthStart, $now),
            'salesTrend' => $orders->salesByDay($trendStart, $now),
            'inventoryValue' => $products->inventoryValue(),
            'openInquiries' => $inquiries->countUnresolved(),
        ]);
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
}
