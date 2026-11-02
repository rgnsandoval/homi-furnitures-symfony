<?php

namespace App\Controller\Admin;

use App\Repository\AuditLogRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class AuditLogController extends AbstractController
{
    #[Route('/admin/audit', name: 'app_admin_audit_log', methods: ['GET'])]
    public function index(Request $request, AuditLogRepository $audit): Response
    {
        $page = max(1, $request->query->getInt('page', 1));

        return $this->render('admin/audit_log/index.html.twig', [
            'entries' => $audit->findPage($page),
            'page' => $page,
        ]);
    }
}
