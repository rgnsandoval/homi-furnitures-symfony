<?php

namespace App\Controller;

use App\Entity\CartItem;
use App\Entity\DiscountCode;
use App\Entity\Order;
use App\Entity\OrderItem;
use App\Entity\Payment;
use App\Entity\Product;
use App\Entity\StockMovement;
use App\Entity\User;
use App\Repository\AddressRepository;
use App\Repository\CartItemRepository;
use App\Repository\DiscountCodeRepository;
use App\Repository\OrderRepository;
use App\Repository\PlatformSettingRepository;
use App\Repository\ProductVariantRepository;
use App\Service\OrderTransitionService;
use App\Service\PaymentTransitionService;
use Doctrine\ORM\EntityManagerInterface;
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
    private const SHIPPING_DAYS = [Order::SHIPPING_STANDARD => 5, Order::SHIPPING_EXPRESS => 2, Order::SHIPPING_PICKUP => 0];

    public function __construct(
        private CartItemRepository $cart,
        private AddressRepository $addresses,
        private DiscountCodeRepository $discountCodes,
        private PlatformSettingRepository $settings,
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
        $quote = $this->quote($user, $this->cart->findForUser($user), $this->input($data));

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
    public function submit(
        Request $request,
        EntityManagerInterface $em,
        OrderRepository $orders,
        ProductVariantRepository $variants,
        OrderTransitionService $orderTransitions,
        PaymentTransitionService $paymentTransitions,
        LockFactory $lockFactory,
    ): Response {
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

            $input = $this->input($request->request->all());
            $items = $this->cart->findForUser($user);
            $quote = $this->quote($user, $items, $input);
            if ([] !== $quote['errors']) {
                return $this->failed($quote['errors']);
            }

            $em->beginTransaction();
            try {
                $locked = $variants->lockForUpdate(array_map(static fn (CartItem $item): int => (int) $item->getVariant()->getId(), $items));

                // Recomputed from the locked rows; nothing the client sent is trusted for prices or stock.
                $quote = $this->quote($user, $items, $input);
                if ([] !== $quote['errors']) {
                    $em->rollback();

                    return $this->failed($quote['errors']);
                }

                if (null !== $quote['discountCode'] && !$this->discountCodes->claimUse($quote['discountCode'])) {
                    $em->rollback();

                    return $this->failed(['This voucher has reached its usage limit.']);
                }

                $order = $this->buildOrder($user, $items, $quote, $input, $key);
                $em->persist($order);

                foreach ($items as $item) {
                    $variant = $locked[$item->getVariant()->getId()];
                    $variant->setStock($variant->getStock() - (int) $item->getQuantity());
                    $orderItem = (new OrderItem())
                        ->setQuantity((int) $item->getQuantity())
                        ->setUnitPrice($variant->getUnitPrice())
                        ->setProductName((string) $variant->getProduct()->getName())
                        ->setVariantName((string) $variant->getName())
                        ->setVariant($variant);
                    $order->addItem($orderItem);
                    $em->persist($orderItem);
                    $em->persist((new StockMovement())
                        ->setVariant($variant)
                        ->setQuantityChange(-(int) $item->getQuantity())
                        ->setMovementReason(StockMovement::REASON_ORDER)
                        ->setNote('Order '.$order->getOrderNumber()));
                }

                $payment = (new Payment())->setMethod($input['paymentMethod'])->setAmount($quote['total']);
                $order->setPayment($payment);
                $em->persist($payment);

                $orderTransitions->recordCreated($order, $user);
                $paymentTransitions->recordCreated($payment, $user);

                $charged = in_array($input['paymentMethod'], [Payment::METHOD_GCASH, Payment::METHOD_CARD], true);
                if ($charged) {
                    $payment->setReference($this->chargeSimulatedGateway($payment));
                    $paymentTransitions->apply($payment, Payment::STATUS_PAID, $user, 'Simulated gateway charge');
                }

                $em->flush();
                $this->cart->clearForUser($user);
                $em->commit();
            } catch (\Throwable $e) {
                $em->rollback();
                throw $e;
            }
        } finally {
            $lock->release();
        }

        $request->getSession()->remove(self::SESSION_KEY);

        // Events run only after commit.
        $orderTransitions->dispatchPlaced($order);
        if ($charged) {
            $paymentTransitions->dispatchChanged($payment, Payment::STATUS_PENDING, Payment::STATUS_PAID);
        }

        $this->addFlash('success', 'Thank you! Your order has been placed.');

        return $this->redirectToRoute('app_order_detail', ['id' => $order->getId()], Response::HTTP_SEE_OTHER);
    }

    /**
     * Stands in for a real gateway. Card numbers are never collected or stored.
     */
    private function chargeSimulatedGateway(Payment $payment): string
    {
        return 'SIM-'.strtoupper((string) $payment->getMethod()).'-'.strtoupper(bin2hex(random_bytes(6)));
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
     * @param list<string> $errors
     */
    private function failed(array $errors): Response
    {
        foreach ($errors as $error) {
            $this->addFlash('error', $error);
        }

        return $this->redirectToRoute('app_checkout', [], Response::HTTP_SEE_OTHER);
    }

    /**
     * @param list<CartItem> $items
     */
    private function buildOrder(User $user, array $items, array $quote, array $input, string $key): Order
    {
        return (new Order())
            ->setOrderNumber('HF-'.date('Ymd').'-'.strtoupper(bin2hex(random_bytes(4))))
            ->setShippingMethod($input['shippingMethod'])
            ->setSubtotal($quote['subtotal'])
            ->setShippingFee($quote['shippingFee'])
            ->setAssemblyFee($quote['assemblyFee'])
            ->setDiscount($quote['discount'])
            ->setTotal($quote['total'])
            ->setAssemblyRequested($input['assembly'])
            ->setEstimatedDeliveryDate($quote['estimatedDeliveryDate'])
            ->setNotes('' === $input['notes'] ? null : $input['notes'])
            ->setIdempotencyKey($key)
            ->setCustomer($user)
            ->setShippingAddress($quote['address'])
            ->setDiscountCode($quote['discountCode']);
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array{address: int, shippingMethod: string, paymentMethod: string, voucher: string, assembly: bool, notes: string}
     */
    private function input(array $data): array
    {
        $text = static fn (string $key): string => is_scalar($data[$key] ?? null) ? trim((string) $data[$key]) : '';

        return [
            'address' => (int) $text('address'),
            'shippingMethod' => $text('shippingMethod'),
            'paymentMethod' => $text('paymentMethod'),
            'voucher' => $text('voucher'),
            'assembly' => filter_var($data['assembly'] ?? false, FILTER_VALIDATE_BOOL),
            'notes' => mb_substr($text('notes'), 0, 500),
        ];
    }

    /**
     * The single source of truth for totals, shared by preview and submit.
     *
     * @param list<CartItem> $items
     */
    private function quote(User $user, array $items, array $input): array
    {
        $errors = [];

        if (1 !== $this->settings->getInt('shop_open', 1)) {
            $errors[] = 'The shop is closed for orders right now.';
        }
        if (!$user->isVerified()) {
            $errors[] = 'Please verify your email address before checking out.';
        }
        if ([] === $items) {
            $errors[] = 'Your cart is empty.';
        }

        $subtotal = '0.00';
        $leadDays = 0;
        foreach ($items as $item) {
            $variant = $item->getVariant();
            $product = $variant->getProduct();

            if (!$variant->isActive() || Product::STATUS_ACTIVE !== $product->getStatus()) {
                $errors[] = sprintf('%s is no longer available.', $product->getName());
            } elseif ($item->getQuantity() > $variant->getStock()) {
                $errors[] = sprintf('Only %d of %s (%s) left in stock.', max(0, $variant->getStock()), $product->getName(), $variant->getName());
            }

            $subtotal = bcadd($subtotal, bcmul($variant->getUnitPrice(), (string) $item->getQuantity(), 2), 2);
            $leadDays = max($leadDays, (int) $product->getLeadTimeDays());
        }

        $address = $this->addresses->find($input['address']);
        if (null === $address || $address->getOwner() !== $user) {
            $address = null;
            $errors[] = 'Please choose one of your saved addresses.';
        }

        $shippingMethod = $input['shippingMethod'];
        if (!in_array($shippingMethod, Order::SHIPPING_METHODS, true)) {
            $errors[] = 'Please choose a shipping method.';
        }
        if (!in_array($input['paymentMethod'], Payment::METHODS, true)) {
            $errors[] = 'Please choose a payment method.';
        }

        $minimum = $this->settings->getDecimal('min_order_amount');
        if ([] !== $items && -1 === bccomp($subtotal, $minimum, 2)) {
            $errors[] = sprintf('The minimum order is PHP %s.', $minimum);
        }

        $discountCode = null;
        $discount = '0.00';
        if ('' !== $input['voucher']) {
            $discountCode = $this->discountCodes->findValidByCode($input['voucher']);
            if (null === $discountCode) {
                $errors[] = 'This voucher is not valid.';
            } elseif (null !== $discountCode->getMinSpend() && -1 === bccomp($subtotal, $discountCode->getMinSpend(), 2)) {
                $errors[] = sprintf('This voucher needs a minimum spend of PHP %s.', $discountCode->getMinSpend());
                $discountCode = null;
            } else {
                $discount = $this->discountAmount($discountCode, $subtotal);
            }
        }

        $shippingFee = $this->shippingFee($shippingMethod, $subtotal);
        $assemblyFee = $input['assembly'] ? $this->settings->getDecimal('assembly_fee') : '0.00';

        $total = bcsub(bcadd(bcadd($subtotal, $shippingFee, 2), $assemblyFee, 2), $discount, 2);
        if (-1 === bccomp($total, '0', 2)) {
            $total = '0.00';
        }

        $days = $leadDays + (self::SHIPPING_DAYS[$shippingMethod] ?? 0);

        return [
            'subtotal' => $subtotal,
            'shippingFee' => $shippingFee,
            'assemblyFee' => $assemblyFee,
            'discount' => $discount,
            'total' => $total,
            'estimatedDeliveryDate' => (new \DateTimeImmutable('today'))->modify("+$days days"),
            'errors' => array_values(array_unique($errors)),
            'discountCode' => $discountCode,
            'address' => $address,
        ];
    }

    private function discountAmount(DiscountCode $code, string $subtotal): string
    {
        $amount = DiscountCode::TYPE_PERCENT === $code->getDiscountType()
            ? bcdiv(bcmul($subtotal, (string) $code->getDiscountValue(), 4), '100', 2)
            : (string) $code->getDiscountValue();

        return 1 === bccomp($amount, $subtotal, 2) ? $subtotal : bcadd($amount, '0', 2);
    }

    private function shippingFee(string $method, string $subtotal): string
    {
        if (Order::SHIPPING_PICKUP === $method) {
            return '0.00';
        }
        if (bccomp($subtotal, $this->settings->getDecimal('free_shipping_threshold'), 2) >= 0) {
            return '0.00';
        }

        return Order::SHIPPING_EXPRESS === $method
            ? $this->settings->getDecimal('express_shipping_fee')
            : $this->settings->getDecimal('shipping_fee');
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
