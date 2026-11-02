<?php

namespace App\Controller\Admin;

use App\Repository\PaymentRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class PaymentController extends AbstractController
{
    #[Route('/admin/payments', name: 'app_admin_payment', methods: ['GET'])]
    public function index(Request $request, PaymentRepository $payments): Response
    {
        $page = max(1, $request->query->getInt('page', 1));

        return $this->render('admin/payment/index.html.twig', [
            'payments' => $payments->findPage($page),
            'page' => $page,
        ]);
    }
}
