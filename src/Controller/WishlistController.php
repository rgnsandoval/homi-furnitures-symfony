<?php

namespace App\Controller;

use App\Entity\Product;
use App\Entity\User;
use App\Entity\WishlistItem;
use App\Repository\WishlistItemRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class WishlistController extends AbstractController
{
    #[Route('/wishlist', name: 'app_wishlist', methods: ['GET'])]
    public function index(Request $request, WishlistItemRepository $wishlist): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $page = max(1, $request->query->getInt('page', 1));

        return $this->render('wishlist/index.html.twig', [
            'items' => $wishlist->findForUser($user, $page),
            'page' => $page,
        ]);
    }

    #[Route('/wishlist/{id}/toggle', name: 'app_wishlist_toggle', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function toggle(Product $product, Request $request, WishlistItemRepository $wishlist, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('wishlist', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        /** @var User $user */
        $user = $this->getUser();
        $item = $wishlist->findOneForUserAndProduct($user, $product);

        if (null === $item) {
            $em->persist((new WishlistItem())->setOwner($user)->setProduct($product));
            $this->addFlash('success', 'Added to your wishlist.');
        } else {
            $em->remove($item);
            $this->addFlash('success', 'Removed from your wishlist.');
        }
        $em->flush();

        return $this->redirectToRoute('app_wishlist', [], Response::HTTP_SEE_OTHER);
    }
}
