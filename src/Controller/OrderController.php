<?php

namespace App\Controller;

use App\Entity\Order;
use App\Entity\User;
use App\Repository\OrderRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class OrderController extends AbstractController
{
    #[Route('/orders', name: 'app_orders', methods: ['GET'])]
    public function index(Request $request, OrderRepository $orders): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $status = $request->query->getString('status');
        if (!in_array($status, Order::STATUSES, true)) {
            $status = '';
        }
        $page = max(1, $request->query->getInt('page', 1));

        return $this->render('order/index.html.twig', [
            'orders' => $orders->findForCustomer($user, $status, $page),
            'status' => $status,
            'statuses' => Order::STATUSES,
            'page' => $page,
        ]);
    }
}
