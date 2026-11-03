<?php

namespace App\Controller\Admin;

use App\Entity\Review;
use App\Entity\User;
use App\Form\AdminReviewType;
use App\Repository\AuditLogRepository;
use App\Repository\ReviewRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ReviewController extends AbstractController
{
    private const FILTERS = ['pending' => false, 'approved' => true, 'all' => null];

    #[Route('/admin/reviews', name: 'app_admin_review', methods: ['GET'])]
    public function index(Request $request, ReviewRepository $reviews): Response
    {
        $page = max(1, $request->query->getInt('page', 1));
        $show = array_key_exists($request->query->getString('show'), self::FILTERS) ? $request->query->getString('show') : 'pending';

        return $this->render('admin/review/index.html.twig', [
            'reviews' => $reviews->findForAdmin(self::FILTERS[$show], $page),
            'show' => $show,
            'page' => $page,
        ]);
    }

    #[Route('/admin/reviews/{id}/edit', name: 'app_admin_review_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(Review $review, Request $request, EntityManagerInterface $em, AuditLogRepository $audit): Response
    {
        $form = $this->createForm(AdminReviewType::class, $review);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();
            $audit->record($this->admin(), 'review.update', 'Review', (int) $review->getId(), ['rating' => $review->getRating(), 'approved' => $review->isApproved()]);
            $this->addFlash('success', 'Review saved.');

            return $this->redirectToRoute('app_admin_review', ['show' => $review->isApproved() ? 'approved' : 'pending'], Response::HTTP_SEE_OTHER);
        }

        return $this->render('admin/review/edit.html.twig', ['review' => $review, 'form' => $form]);
    }

    #[Route('/admin/reviews/{id}/approve', name: 'app_admin_review_approve', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function approve(Review $review, Request $request, EntityManagerInterface $em): Response
    {
        $this->checkToken($request, $review);
        $review->setIsApproved(true);
        $em->flush();
        $this->addFlash('success', 'Review approved.');

        return $this->back($request);
    }

    #[Route('/admin/reviews/{id}/delete', name: 'app_admin_review_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(
        Review $review,
        Request $request,
        EntityManagerInterface $em,
        AuditLogRepository $audit,
        #[Autowire('%kernel.project_dir%/public/uploads/reviews')] string $reviewDir,
    ): Response {
        $this->checkToken($request, $review);
        $photo = $review->getPhoto();
        $id = (int) $review->getId();
        $summary = ['product' => $review->getProduct()?->getName(), 'author' => $review->getAuthor()?->getEmail(), 'rating' => $review->getRating()];
        $em->remove($review);
        $em->flush();

        if (null !== $photo && is_file($reviewDir.'/'.basename($photo))) {
            unlink($reviewDir.'/'.basename($photo));
        }
        $audit->record($this->admin(), 'review.delete', 'Review', $id, $summary);
        $this->addFlash('success', 'Review deleted.');

        return $this->back($request);
    }

    private function back(Request $request): Response
    {
        $show = $request->request->getString('_show');

        return $this->redirectToRoute('app_admin_review', array_key_exists($show, self::FILTERS) ? ['show' => $show] : [], Response::HTTP_SEE_OTHER);
    }

    private function checkToken(Request $request, Review $review): void
    {
        if (!$this->isCsrfTokenValid('review'.$review->getId(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }

    private function admin(): User
    {
        /** @var User $user */
        $user = $this->getUser();

        return $user;
    }
}
