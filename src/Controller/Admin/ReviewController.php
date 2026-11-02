<?php

namespace App\Controller\Admin;

use App\Entity\Review;
use App\Repository\ReviewRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ReviewController extends AbstractController
{
    #[Route('/admin/reviews', name: 'app_admin_review', methods: ['GET'])]
    public function index(Request $request, ReviewRepository $reviews): Response
    {
        $page = max(1, $request->query->getInt('page', 1));

        return $this->render('admin/review/index.html.twig', [
            'reviews' => $reviews->findPending($page),
            'page' => $page,
        ]);
    }

    #[Route('/admin/reviews/{id}/approve', name: 'app_admin_review_approve', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function approve(Review $review, Request $request, EntityManagerInterface $em): Response
    {
        $this->checkToken($request, $review);
        $review->setIsApproved(true);
        $em->flush();
        $this->addFlash('success', 'Review approved.');

        return $this->redirectToRoute('app_admin_review', [], Response::HTTP_SEE_OTHER);
    }

    #[Route('/admin/reviews/{id}/delete', name: 'app_admin_review_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(
        Review $review,
        Request $request,
        EntityManagerInterface $em,
        #[Autowire('%kernel.project_dir%/public/uploads/reviews')] string $reviewDir,
    ): Response {
        $this->checkToken($request, $review);
        $photo = $review->getPhoto();
        $em->remove($review);
        $em->flush();

        if (null !== $photo && is_file($reviewDir.'/'.basename($photo))) {
            unlink($reviewDir.'/'.basename($photo));
        }
        $this->addFlash('success', 'Review deleted.');

        return $this->redirectToRoute('app_admin_review', [], Response::HTTP_SEE_OTHER);
    }

    private function checkToken(Request $request, Review $review): void
    {
        if (!$this->isCsrfTokenValid('review'.$review->getId(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }
}
