<?php

namespace App\Controller;

use App\Entity\Order;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class InvoiceController extends AbstractController
{
    #[Route('/orders/{id}/invoice', name: 'app_invoice', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function index(Order $order): Response
    {
        if ($order->getCustomer() !== $this->getUser() && !$this->isGranted('ROLE_ADMIN')) {
            throw $this->createNotFoundException();
        }

        return $this->render('invoice/index.html.twig', ['order' => $order]);
    }
}
