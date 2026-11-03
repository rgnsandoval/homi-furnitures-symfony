<?php

namespace App\Service;

use App\Entity\CartItem;
use App\Entity\DiscountCode;
use App\Entity\Order;
use App\Entity\OrderItem;
use App\Entity\Payment;
use App\Entity\Product;
use App\Entity\StockMovement;
use App\Entity\User;
use App\Repository\AddressRepository;
use App\Repository\DiscountCodeRepository;
use App\Repository\PlatformSettingRepository;
use App\Repository\ProductVariantRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Prices and places orders. The storefront checkout and the admin "new order" screen both go through here,
 * so totals, stock, vouchers and history follow one set of rules.
 */
class CheckoutService
{
    private const SHIPPING_DAYS = [Order::SHIPPING_STANDARD => 5, Order::SHIPPING_EXPRESS => 2, Order::SHIPPING_PICKUP => 0];

    public function __construct(
        private EntityManagerInterface $em,
        private AddressRepository $addresses,
        private DiscountCodeRepository $discountCodes,
        private PlatformSettingRepository $settings,
        private ProductVariantRepository $variants,
        private OrderTransitionService $orderTransitions,
        private PaymentTransitionService $paymentTransitions,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array{address: int, shippingMethod: string, paymentMethod: string, voucher: string, assembly: bool, notes: string}
     */
    public function input(array $data): array
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
     * Staff orders skip the "shop open" and "email verified" checks, which only gate self-service checkout.
     *
     * @param list<CartItem|OrderLine> $items
     * @param array{address: int, shippingMethod: string, paymentMethod: string, voucher: string, assembly: bool, notes: string} $input
     */
    public function quote(User $user, array $items, array $input, bool $staff = false): array
    {
        $errors = [];

        if (!$staff && 1 !== $this->settings->getInt('shop_open', 1)) {
            $errors[] = 'The shop is closed for orders right now.';
        }
        if (!$staff && !$user->isVerified()) {
            $errors[] = 'Please verify your email address before checking out.';
        }
        if ([] === $items) {
            $errors[] = $staff ? 'Add at least one item.' : 'Your cart is empty.';
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
            $errors[] = $staff ? 'Choose one of the customer\'s saved addresses.' : 'Please choose one of your saved addresses.';
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

    /**
     * Places the order in one transaction: locks the variants, re-prices from the locked rows, claims the voucher,
     * takes the stock, creates the payment and both histories. Events are dispatched only after commit.
     *
     * @param list<CartItem|OrderLine> $items
     * @param array{address: int, shippingMethod: string, paymentMethod: string, voucher: string, assembly: bool, notes: string} $input
     * @param (callable(): void)|null $beforeCommit runs inside the transaction, e.g. to clear the cart
     *
     * @return array{order: ?Order, errors: list<string>}
     */
    public function place(User $customer, array $items, array $input, string $idempotencyKey, User $actor, bool $staff = false, ?callable $beforeCommit = null): array
    {
        $quote = $this->quote($customer, $items, $input, $staff);
        if ([] !== $quote['errors']) {
            return ['order' => null, 'errors' => $quote['errors']];
        }

        $this->em->beginTransaction();
        try {
            $locked = $this->variants->lockForUpdate(array_map(static fn (CartItem|OrderLine $item): int => (int) $item->getVariant()->getId(), $items));

            // Recomputed from the locked rows; nothing the client sent is trusted for prices or stock.
            $quote = $this->quote($customer, $items, $input, $staff);
            if ([] !== $quote['errors']) {
                $this->em->rollback();

                return ['order' => null, 'errors' => $quote['errors']];
            }

            if (null !== $quote['discountCode'] && !$this->discountCodes->claimUse($quote['discountCode'])) {
                $this->em->rollback();

                return ['order' => null, 'errors' => ['This voucher has reached its usage limit.']];
            }

            $order = (new Order())
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
                ->setIdempotencyKey($idempotencyKey)
                ->setCustomer($customer)
                ->setShippingAddress($quote['address'])
                ->setDiscountCode($quote['discountCode']);
            $this->em->persist($order);

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
                $this->em->persist($orderItem);
                $this->em->persist((new StockMovement())
                    ->setVariant($variant)
                    ->setQuantityChange(-(int) $item->getQuantity())
                    ->setMovementReason(StockMovement::REASON_ORDER)
                    ->setNote('Order '.$order->getOrderNumber())
                    ->setAdminUser($staff ? $actor : null));
            }

            $payment = (new Payment())->setMethod($input['paymentMethod'])->setAmount($quote['total']);
            $order->setPayment($payment);
            $this->em->persist($payment);

            $this->orderTransitions->recordCreated($order, $actor);
            $this->paymentTransitions->recordCreated($payment, $actor);

            $charged = in_array($input['paymentMethod'], [Payment::METHOD_GCASH, Payment::METHOD_CARD], true);
            if ($charged) {
                $payment->setReference($this->chargeSimulatedGateway($payment));
                $this->paymentTransitions->apply($payment, Payment::STATUS_PAID, $actor, 'Simulated gateway charge');
            }

            $this->em->flush();
            if (null !== $beforeCommit) {
                $beforeCommit();
            }
            $this->em->commit();
        } catch (\Throwable $e) {
            $this->em->rollback();
            throw $e;
        }

        $this->orderTransitions->dispatchPlaced($order);
        if ($charged) {
            $this->paymentTransitions->dispatchChanged($payment, Payment::STATUS_PENDING, Payment::STATUS_PAID);
        }

        return ['order' => $order, 'errors' => []];
    }

    /**
     * Stands in for a real gateway. Card numbers are never collected or stored.
     */
    private function chargeSimulatedGateway(Payment $payment): string
    {
        return 'SIM-'.strtoupper((string) $payment->getMethod()).'-'.strtoupper(bin2hex(random_bytes(6)));
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
}
