<?php

namespace App\Controller\Admin;

use App\Entity\Order;
use App\Repository\OrderRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class OrderController extends AbstractController
{
    #[Route('/admin/orders', name: 'app_admin_order', methods: ['GET'])]
    public function index(Request $request, OrderRepository $orders): Response
    {
        $date = static fn (string $value): string => 1 === preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : '';
        $status = $request->query->getString('status');

        $filters = [
            'status' => in_array($status, Order::STATUSES, true) ? $status : '',
            'q' => $request->query->getString('q'),
            'from' => $date($request->query->getString('from')),
            'to' => $date($request->query->getString('to')),
        ];
        $page = max(1, $request->query->getInt('page', 1));

        return $this->render('admin/order/index.html.twig', [
            'orders' => $orders->findForAdmin($filters, $page),
            'filters' => $filters,
            'statuses' => Order::STATUSES,
            'page' => $page,
        ]);
    }
}
