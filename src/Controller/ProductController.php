<?php

namespace App\Controller;

use App\Entity\Review;
use App\Entity\User;
use App\Form\ReviewFormType;
use App\Repository\ProductRepository;
use App\Repository\ReviewRepository;
use App\Repository\WishlistItemRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ProductController extends AbstractController
{
    #[Route('/product/{slug}', name: 'app_product', methods: ['GET', 'POST'])]
    public function index(
        string $slug,
        Request $request,
        ProductRepository $products,
        ReviewRepository $reviews,
        WishlistItemRepository $wishlist,
        EntityManagerInterface $em,
        #[Autowire('%kernel.project_dir%/public/uploads/reviews')] string $reviewDir,
    ): Response {
        $product = $products->findActiveBySlug($slug);
        if (null === $product) {
            throw $this->createNotFoundException();
        }

        /** @var User|null $user */
        $user = $this->getUser();
        $reviewForm = null;

        if (null !== $user && null === $reviews->findOneForAuthorAndProduct($user, $product)) {
            $review = (new Review())->setAuthor($user)->setProduct($product);
            $reviewForm = $this->createForm(ReviewFormType::class, $review);
            $reviewForm->handleRequest($request);

            if ($reviewForm->isSubmitted() && $reviewForm->isValid()) {
                /** @var UploadedFile|null $photo */
                $photo = $reviewForm->get('photoFile')->getData();
                if (null !== $photo) {
                    $filename = bin2hex(random_bytes(16)).'.'.$photo->guessExtension();
                    $photo->move($reviewDir, $filename);
                    $review->setPhoto($filename);
                }

                $em->persist($review);
                $em->flush();
                $this->addFlash('success', 'Thanks! Your review will appear once it is approved.');

                return $this->redirectToRoute('app_product', ['slug' => $slug], Response::HTTP_SEE_OTHER);
            }
        } elseif ($request->isMethod('POST')) {
            return $this->redirectToRoute(null === $user ? 'app_login' : 'app_product', null === $user ? [] : ['slug' => $slug], Response::HTTP_SEE_OTHER);
        }

        $page = max(1, $request->query->getInt('page', 1));

        return $this->render('product/index.html.twig', [
            'product' => $product,
            'page' => $page,
            'variants' => $product->getVariants()->filter(static fn ($variant) => $variant->isActive()),
            'reviews' => $reviews->findApprovedForProduct($product, $page),
            'averageRating' => $reviews->averageRating($product),
            'related' => $products->findRelated($product),
            'reviewForm' => $reviewForm,
            'inWishlist' => null !== $user && null !== $wishlist->findOneForUserAndProduct($user, $product),
        ]);
    }
}
