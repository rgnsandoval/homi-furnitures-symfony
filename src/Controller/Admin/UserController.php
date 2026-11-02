<?php

namespace App\Controller\Admin;

use App\Entity\User;
use App\Repository\AuditLogRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class UserController extends AbstractController
{
    #[Route('/admin/users', name: 'app_admin_user', methods: ['GET'])]
    public function index(Request $request, UserRepository $users): Response
    {
        $page = max(1, $request->query->getInt('page', 1));
        $q = $request->query->getString('q');

        return $this->render('admin/user/index.html.twig', [
            'users' => $users->findPage($q, $page),
            'q' => $q,
            'page' => $page,
        ]);
    }

    #[Route('/admin/users/{id}/toggle', name: 'app_admin_user_toggle', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function toggle(User $user, Request $request, EntityManagerInterface $em, AuditLogRepository $audit): Response
    {
        if (!$this->isCsrfTokenValid('user'.$user->getId(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        /** @var User $admin */
        $admin = $this->getUser();

        if ($user === $admin) {
            $this->addFlash('error', 'You cannot deactivate your own account.');
        } else {
            $user->setIsActive(!$user->isActive());
            $em->flush();
            $audit->record($admin, $user->isActive() ? 'user.activate' : 'user.deactivate', 'User', (int) $user->getId());
            $this->addFlash('success', sprintf('%s is now %s.', $user->getEmail(), $user->isActive() ? 'active' : 'deactivated'));
        }

        return $this->redirectToRoute('app_admin_user', [], Response::HTTP_SEE_OTHER);
    }
}
