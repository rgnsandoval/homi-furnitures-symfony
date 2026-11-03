<?php

namespace App\Controller\Admin;

use App\Entity\Inquiry;
use App\Entity\User;
use App\Repository\AuditLogRepository;
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

    #[Route('/admin/inquiries/{id}', name: 'app_admin_inquiry_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(Inquiry $inquiry): Response
    {
        return $this->render('admin/inquiry/show.html.twig', ['inquiry' => $inquiry, 'statuses' => Inquiry::STATUSES]);
    }

    #[Route('/admin/inquiries/{id}/status', name: 'app_admin_inquiry_status', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function status(Inquiry $inquiry, Request $request, EntityManagerInterface $em): Response
    {
        $this->checkToken($request, $inquiry);

        $status = $request->request->getString('status');
        if (in_array($status, Inquiry::STATUSES, true)) {
            $inquiry->setStatus($status);
            $em->flush();
            $this->addFlash('success', 'Status saved.');
        }

        return 'show' === $request->request->getString('_back')
            ? $this->redirectToRoute('app_admin_inquiry_show', ['id' => $inquiry->getId()], Response::HTTP_SEE_OTHER)
            : $this->redirectToRoute('app_admin_inquiry', [], Response::HTTP_SEE_OTHER);
    }

    #[Route('/admin/inquiries/{id}/delete', name: 'app_admin_inquiry_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(Inquiry $inquiry, Request $request, EntityManagerInterface $em, AuditLogRepository $audit): Response
    {
        $this->checkToken($request, $inquiry);
        $id = (int) $inquiry->getId();
        $summary = ['from' => $inquiry->getEmail(), 'subject' => $inquiry->getSubject()];
        $em->remove($inquiry);
        $em->flush();

        /** @var User $admin */
        $admin = $this->getUser();
        $audit->record($admin, 'inquiry.delete', 'Inquiry', $id, $summary);
        $this->addFlash('success', 'Inquiry deleted.');

        return $this->redirectToRoute('app_admin_inquiry', [], Response::HTTP_SEE_OTHER);
    }

    private function checkToken(Request $request, Inquiry $inquiry): void
    {
        if (!$this->isCsrfTokenValid('inquiry'.$inquiry->getId(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }
}
