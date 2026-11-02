<?php

namespace App\Controller\Admin;

use App\Entity\Inquiry;
use App\Repository\InquiryRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class InquiryController extends AbstractController
{
    #[Route('/admin/inquiries', name: 'app_admin_inquiry', methods: ['GET'])]
    public function index(Request $request, InquiryRepository $inquiries): Response
    {
        $page = max(1, $request->query->getInt('page', 1));

        return $this->render('admin/inquiry/index.html.twig', [
            'inquiries' => $inquiries->findPage($page),
            'statuses' => Inquiry::STATUSES,
            'page' => $page,
        ]);
    }

    #[Route('/admin/inquiries/{id}/status', name: 'app_admin_inquiry_status', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function status(Inquiry $inquiry, Request $request, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('inquiry'.$inquiry->getId(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $status = $request->request->getString('status');
        if (in_array($status, Inquiry::STATUSES, true)) {
            $inquiry->setStatus($status);
            $em->flush();
        }

        return $this->redirectToRoute('app_admin_inquiry', [], Response::HTTP_SEE_OTHER);
    }
}
