<?php

namespace App\Controller;

use App\Entity\CartItem;
use App\Entity\Product;
use App\Entity\User;
use App\Repository\CartItemRepository;
use App\Repository\ProductVariantRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class CartController extends AbstractController
{
    #[Route('/cart', name: 'app_cart', methods: ['GET'])]
    public function index(CartItemRepository $cart): Response
    {
        $items = $cart->findForUser($this->currentUser());
        $subtotal = '0.00';
        foreach ($items as $item) {
            $subtotal = bcadd($subtotal, $item->getLineTotal(), 2);
        }

        return $this->render('cart/index.html.twig', ['items' => $items, 'subtotal' => $subtotal]);
    }

    #[Route('/cart/add', name: 'app_cart_add', methods: ['POST'])]
    public function add(Request $request, CartItemRepository $cart, ProductVariantRepository $variants, EntityManagerInterface $em): Response
    {
        $this->checkToken($request);
        $user = $this->currentUser();
        $variant = $variants->find($request->request->getInt('variant'));

        if (null === $variant || !$variant->isActive() || Product::STATUS_ACTIVE !== $variant->getProduct()->getStatus()) {
            throw $this->createNotFoundException();
        }

        $quantity = max(1, $request->request->getInt('quantity', 1));
        // An existing row is increased, so the same variant is never listed twice.
        $item = $cart->findOneForUserAndVariant($user, $variant);
        $wanted = $quantity + ($item?->getQuantity() ?? 0);

        if ($wanted > $variant->getStock()) {
            $this->addFlash('error', sprintf('Only %d of %s available.', max(0, $variant->getStock()), $variant->getName()));

            return $this->redirectToRoute('app_cart', [], Response::HTTP_SEE_OTHER);
        }

        if (null === $item) {
            $item = (new CartItem())->setOwner($user)->setVariant($variant);
            $em->persist($item);
        }
        $item->setQuantity($wanted)->setUpdatedAt(new \DateTimeImmutable());
        $em->flush();
        $this->addFlash('success', 'Added to your cart.');

        return $this->redirectToRoute('app_cart', [], Response::HTTP_SEE_OTHER);
    }

    #[Route('/cart/{id}/update', name: 'app_cart_update', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function update(CartItem $item, Request $request, EntityManagerInterface $em): Response
    {
        $this->checkToken($request);
        $this->checkOwner($item);
        $quantity = $request->request->getInt('quantity');

        if ($quantity <= 0) {
            $em->remove($item);
        } elseif ($quantity > $item->getVariant()->getStock()) {
            $this->addFlash('error', sprintf('Only %d of %s available.', max(0, $item->getVariant()->getStock()), $item->getVariant()->getName()));
        } else {
            $item->setQuantity($quantity)->setUpdatedAt(new \DateTimeImmutable());
        }
        $em->flush();

        return $this->redirectToRoute('app_cart', [], Response::HTTP_SEE_OTHER);
    }

    #[Route('/cart/{id}/remove', name: 'app_cart_remove', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function remove(CartItem $item, Request $request, EntityManagerInterface $em): Response
    {
        $this->checkToken($request);
        $this->checkOwner($item);
        $em->remove($item);
        $em->flush();

        return $this->redirectToRoute('app_cart', [], Response::HTTP_SEE_OTHER);
    }

    private function currentUser(): User
    {
        /** @var User $user */
        $user = $this->getUser();

        return $user;
    }

    private function checkOwner(CartItem $item): void
    {
        if ($item->getOwner() !== $this->getUser()) {
            throw $this->createNotFoundException();
        }
    }

    private function checkToken(Request $request): void
    {
        if (!$this->isCsrfTokenValid('cart', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }
}
