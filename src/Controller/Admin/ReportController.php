<?php

namespace App\Controller\Admin;

use App\Repository\CategoryRepository;
use App\Repository\OrderRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;

final class ReportController extends AbstractController
{
    #[Route('/admin/reports', name: 'app_admin_report', methods: ['GET'])]
    public function index(Request $request, OrderRepository $orders, CategoryRepository $categories): Response
    {
        [$from, $to] = $this->range($request);

        return $this->render('admin/report/index.html.twig', [
            'from' => $from,
            'to' => $to,
            'days' => $orders->salesByDay($from, $to),
            'revenue' => $orders->totalRevenueBetween($from, $to),
            'orderCount' => $orders->countBetween($from, $to),
            'averageOrderValue' => $orders->averageOrderValue($from, $to),
            'cancellationRate' => $orders->cancellationRate($from, $to),
            'fulfillmentHours' => $orders->averageFulfillmentHours($from, $to),
            'byCategory' => $categories->revenueByCategory($from, $to),
        ]);
    }

    #[Route('/admin/reports/export', name: 'app_admin_report_export', methods: ['GET'])]
    public function export(Request $request, OrderRepository $orders): StreamedResponse
    {
        [$from, $to] = $this->range($request);
        $days = $orders->salesByDay($from, $to);

        $response = new StreamedResponse(static function () use ($days): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Day', 'Orders', 'Revenue'], escape: '');
            foreach ($days as $row) {
                fputcsv($out, [$row['day'], $row['orders'], $row['revenue']], escape: '');
            }
            fclose($out);
        });
        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', sprintf('attachment; filename="sales-%s-to-%s.csv"', $from->format('Y-m-d'), $to->format('Y-m-d')));

        return $response;
    }

    /**
     * @return array{\DateTimeImmutable, \DateTimeImmutable}
     */
    private function range(Request $request): array
    {
        $parse = static function (string $value, string $time): ?\DateTimeImmutable {
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

            return false === $date ? null : $date->modify($time);
        };

        $to = $parse($request->query->getString('to'), '23:59:59') ?? new \DateTimeImmutable('today 23:59:59');
        $from = $parse($request->query->getString('from'), '00:00:00') ?? $to->modify('-29 days')->setTime(0, 0);

        return $from > $to ? [$to->setTime(0, 0), $from->setTime(23, 59, 59)] : [$from, $to];
    }
}
