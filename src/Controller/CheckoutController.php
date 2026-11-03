<?php

namespace App\Controller;

use App\Entity\CartItem;
use App\Entity\Order;
use App\Entity\Payment;
use App\Entity\User;
use App\Repository\AddressRepository;
use App\Repository\CartItemRepository;
use App\Repository\OrderRepository;
use App\Service\CheckoutService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

final class CheckoutController extends AbstractController
{
    private const SESSION_KEY = 'checkout_idempotency';

    public function __construct(
        private CartItemRepository $cart,
        private AddressRepository $addresses,
        private CheckoutService $checkout,
    ) {
    }

    #[Route('/checkout', name: 'app_checkout', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $user = $this->currentUser();
        if (!$user->isVerified()) {
            return $this->redirectToRoute('app_verify_notice');
        }

        $items = $this->cart->findForUser($user);
        if ([] === $items) {
            $this->addFlash('error', 'Your cart is empty.');

            return $this->redirectToRoute('app_cart');
        }

        return $this->render('checkout/index.html.twig', [
            'items' => $items,
            'addresses' => $this->addresses->findBy(['owner' => $user], ['isDefault' => 'DESC', 'id' => 'ASC']),
            'shippingMethods' => Order::SHIPPING_METHODS,
            'paymentMethods' => Payment::METHODS,
            'idempotencyKey' => $this->idempotencyKey($request, $items),
        ]);
    }

    /**
     * The frontend renders only these numbers.
     */
    #[Route('/checkout/preview', name: 'app_checkout_preview', methods: ['POST'])]
    public function preview(Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValid('checkout', (string) $request->headers->get('X-CSRF-Token'))) {
            return $this->json(['errors' => ['Invalid CSRF token.']], Response::HTTP_FORBIDDEN);
        }

        try {
            $data = $request->toArray();
        } catch (\Throwable) {
            return $this->json(['errors' => ['Invalid request.']], Response::HTTP_BAD_REQUEST);
        }

        $user = $this->currentUser();
        $quote = $this->checkout->quote($user, $this->cart->findForUser($user), $this->checkout->input($data));

        return $this->json([
            'subtotal' => $quote['subtotal'],
            'shippingFee' => $quote['shippingFee'],
            'assemblyFee' => $quote['assemblyFee'],
            'discount' => $quote['discount'],
            'total' => $quote['total'],
            'estimatedDeliveryDate' => $quote['estimatedDeliveryDate']?->format('Y-m-d'),
            'errors' => $quote['errors'],
        ]);
    }

    #[Route('/checkout', name: 'app_checkout_submit', methods: ['POST'])]
    public function submit(Request $request, OrderRepository $orders, LockFactory $lockFactory): Response
    {
        if (!$this->isCsrfTokenValid('checkout', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $user = $this->currentUser();
        if (!$user->isVerified()) {
            return $this->redirectToRoute('app_verify_notice');
        }

        $key = $request->request->getString('idempotencyKey');
        if (!Uuid::isValid($key)) {
            $this->addFlash('error', 'Your checkout session expired. Please try again.');

            return $this->redirectToRoute('app_checkout');
        }

        if (null !== $response = $this->existingOrderResponse($orders, $key, $user)) {
            return $response;
        }

        $lock = $lockFactory->createLock('checkout-'.$key, 30);
        if (!$lock->acquire()) {
            $this->addFlash('error', 'Your order is already being processed.');

            return $this->redirectToRoute('app_orders');
        }

        try {
            // Another request may have finished while this one waited for the lock.
            if (null !== $response = $this->existingOrderResponse($orders, $key, $user)) {
                return $response;
            }

            $result = $this->checkout->place(
                $user,
                $this->cart->findForUser($user),
                $this->checkout->input($request->request->all()),
                $key,
                $user,
                beforeCommit: fn () => $this->cart->clearForUser($user),
            );
        } finally {
            $lock->release();
        }

        if (null === $order = $result['order']) {
            foreach ($result['errors'] as $error) {
                $this->addFlash('error', $error);
            }

            return $this->redirectToRoute('app_checkout', [], Response::HTTP_SEE_OTHER);
        }

        $request->getSession()->remove(self::SESSION_KEY);
        $this->addFlash('success', 'Thank you! Your order has been placed.');

        return $this->redirectToRoute('app_order_detail', ['id' => $order->getId()], Response::HTTP_SEE_OTHER);
    }

    private function existingOrderResponse(OrderRepository $orders, string $key, User $user): ?Response
    {
        $existing = $orders->findByIdempotencyKey($key);
        if (null === $existing) {
            return null;
        }
        if ($existing->getCustomer() !== $user) {
            throw $this->createAccessDeniedException();
        }

        return $this->redirectToRoute('app_order_detail', ['id' => $existing->getId()], Response::HTTP_SEE_OTHER);
    }

    /**
     * The key stays the same until the cart changes, so a double submit finds the first order.
     *
     * @param list<CartItem> $items
     */
    private function idempotencyKey(Request $request, array $items): string
    {
        $fingerprint = sha1(implode('|', array_map(static fn (CartItem $item): string => $item->getVariant()->getId().':'.$item->getQuantity(), $items)));
        $session = $request->getSession();
        $stored = $session->get(self::SESSION_KEY);

        if (is_array($stored) && $stored['fingerprint'] === $fingerprint) {
            return $stored['key'];
        }

        $key = Uuid::v4()->toRfc4122();
        $session->set(self::SESSION_KEY, ['fingerprint' => $fingerprint, 'key' => $key]);

        return $key;
    }

    private function currentUser(): User
    {
        /** @var User $user */
        $user = $this->getUser();

        return $user;
    }
}
