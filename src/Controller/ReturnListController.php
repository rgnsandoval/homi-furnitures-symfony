<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\ReturnRequestRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ReturnListController extends AbstractController
{
    #[Route('/orders/returns', name: 'app_return_list', methods: ['GET'])]
    public function index(Request $request, ReturnRequestRepository $returns): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $page = max(1, $request->query->getInt('page', 1));

        return $this->render('return_list/index.html.twig', [
            'returns' => $returns->findForCustomer($user, $page),
            'page' => $page,
        ]);
    }
}
