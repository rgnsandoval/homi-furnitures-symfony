<?php

namespace App\DataFixtures;

use App\Entity\Address;
use App\Entity\CartItem;
use App\Entity\DiscountCode;
use App\Entity\Inquiry;
use App\Entity\Order;
use App\Entity\OrderItem;
use App\Entity\OrderStatusHistory;
use App\Entity\Payment;
use App\Entity\PaymentStatusHistory;
use App\Entity\Product;
use App\Entity\ProductVariant;
use App\Entity\ReturnRequest;
use App\Entity\Review;
use App\Entity\StockMovement;
use App\Entity\User;
use App\Entity\WishlistItem;
use App\Repository\PlatformSettingRepository;
use App\Service\OrderTransitionService;
use App\Service\PaymentTransitionService;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Uid\Uuid;

/**
 * Status changes go through the real transition services. Run with APP_SEEDING=1 so no email is sent.
 */
class DemoOrderFixtures extends Fixture implements DependentFixtureInterface
{
    private const PATHS = [
        Order::STATUS_PENDING => [],
        Order::STATUS_CONFIRMED => [Order::STATUS_CONFIRMED],
        Order::STATUS_PACKED => [Order::STATUS_CONFIRMED, Order::STATUS_PACKED],
        Order::STATUS_SHIPPED => [Order::STATUS_CONFIRMED, Order::STATUS_PACKED, Order::STATUS_SHIPPED],
        Order::STATUS_DELIVERED => [Order::STATUS_CONFIRMED, Order::STATUS_PACKED, Order::STATUS_SHIPPED, Order::STATUS_DELIVERED],
    ];

    private int $sequence = 0;
    private User $admin;
    private ObjectManager $manager;

    public function __construct(
        private OrderTransitionService $orders,
        private PaymentTransitionService $payments,
        private PlatformSettingRepository $settings,
        #[Autowire('%kernel.project_dir%')] private string $projectDir,
        private Filesystem $filesystem = new Filesystem(),
    ) {
    }

    public function getDependencies(): array
    {
        return [PlatformSettingFixtures::class, UserFixtures::class, CatalogFixtures::class];
    }

    public function load(ObjectManager $manager): void
    {
        $this->manager = $manager;
        $this->admin = $this->getReference(UserFixtures::ADMIN, User::class);
        // The database was just purged, so proofs from an earlier seed are orphans.
        $this->filesystem->remove($this->projectDir.'/var/uploads/proofs');
        $this->filesystem->mkdir($this->projectDir.'/var/uploads/proofs');

        $customers = [];
        foreach (UserFixtures::CUSTOMERS as $reference) {
            $customer = $this->getReference($reference, User::class);
            $customers[] = [$customer, $this->address($customer)];
        }
        [$c1, $c2, $c3] = $customers;

        $voucher = (new DiscountCode())->setCode('WELCOME10')->setDiscountType(DiscountCode::TYPE_PERCENT)->setDiscountValue('10.00')->setMinSpend('5000.00');
        $manager->persist($voucher);
        $manager->persist((new DiscountCode())->setCode('LESS500')->setDiscountType(DiscountCode::TYPE_FIXED)->setDiscountValue('500.00')->setUsageLimit(50));
        $manager->flush();

        // Days ago, target status, payment method, customer, variant, quantity.
        $this->place($c1, '1-1', 1, Payment::METHOD_COD, Order::STATUS_PENDING, 2, Order::SHIPPING_STANDARD);
        $this->place($c2, '3-1', 2, Payment::METHOD_BANK, Order::STATUS_PENDING, 3, Order::SHIPPING_STANDARD, proof: true);
        $this->place($c3, '5-1', 1, Payment::METHOD_GCASH, Order::STATUS_CONFIRMED, 5, Order::SHIPPING_EXPRESS);
        $this->place($c1, '7-1', 2, Payment::METHOD_CARD, Order::STATUS_CONFIRMED, 7, Order::SHIPPING_PICKUP);
        $this->place($c2, '10-1', 1, Payment::METHOD_BANK, Order::STATUS_PACKED, 10, Order::SHIPPING_STANDARD, proof: true, approveFirst: true);
        $this->place($c3, '13-1', 1, Payment::METHOD_GCASH, Order::STATUS_PACKED, 12, Order::SHIPPING_STANDARD, assembly: true);
        $this->place($c1, '15-1', 1, Payment::METHOD_COD, Order::STATUS_SHIPPED, 14, Order::SHIPPING_EXPRESS);
        $this->place($c2, '17-1', 1, Payment::METHOD_CARD, Order::STATUS_SHIPPED, 16, Order::SHIPPING_STANDARD);
        $this->place($c1, '19-1', 1, Payment::METHOD_COD, Order::STATUS_DELIVERED, 40, Order::SHIPPING_STANDARD, collect: true);
        $this->place($c3, '21-1', 3, Payment::METHOD_GCASH, Order::STATUS_DELIVERED, 30, Order::SHIPPING_STANDARD, voucher: $voucher);
        $this->place($c2, '23-1', 1, Payment::METHOD_COD, Order::STATUS_DELIVERED, 20, Order::SHIPPING_STANDARD);
        $returned = $this->place($c3, '25-1', 1, Payment::METHOD_CARD, Order::STATUS_DELIVERED, 6, Order::SHIPPING_STANDARD, deliveredDaysAgo: 1);
        $this->place($c1, '27-1', 1, Payment::METHOD_COD, Order::STATUS_CANCELLED, 25, Order::SHIPPING_STANDARD);
        $this->place($c2, '29-1', 1, Payment::METHOD_BANK, Order::STATUS_CANCELLED, 18, Order::SHIPPING_STANDARD);
        $this->place($c3, '31-1', 1, Payment::METHOD_GCASH, Order::STATUS_CANCELLED, 22, Order::SHIPPING_STANDARD);

        $manager->persist((new ReturnRequest())
            ->setReturnReason('The legs arrived scratched.')
            ->setParentOrder($returned)
            ->setCustomer($c3[0])
            ->setCreatedAt(new \DateTimeImmutable('-12 hours')));

        $this->reviews($c1[0], $c2[0], $c3[0]);

        $manager->persist((new CartItem())->setOwner($c1[0])->setVariant($this->variant('2-1'))->setQuantity(1));
        $manager->persist((new CartItem())->setOwner($c2[0])->setVariant($this->variant('4-1'))->setQuantity(2));
        $manager->persist((new WishlistItem())->setOwner($c1[0])->setProduct($this->getReference(CatalogFixtures::PRODUCT_PREFIX.'6', Product::class)));
        $manager->persist((new WishlistItem())->setOwner($c3[0])->setProduct($this->getReference(CatalogFixtures::PRODUCT_PREFIX.'10', Product::class)));

        foreach ([
            ['Liza Tan', 'Delivery to Cebu', 'Do you deliver sofas to Cebu City?', Inquiry::STATUS_NEW],
            ['Mark Lim', 'Bulk order', 'Can you quote twenty dining chairs?', Inquiry::STATUS_READ],
            ['Grace Uy', 'Assembly', 'Thanks, the assembly team was great.', Inquiry::STATUS_RESOLVED],
        ] as $index => [$name, $subject, $message, $status]) {
            $manager->persist((new Inquiry())
                ->setName($name)
                ->setEmail(strtolower(strtok($name, ' ')).'@example.test')
                ->setSubject($subject)
                ->setMessage($message)
                ->setStatus($status)
                ->setCreatedAt(new \DateTimeImmutable('-'.($index + 1).' days')));
        }

        $manager->flush();
    }

    private function address(User $customer): Address
    {
        $address = (new Address())
            ->setLabel('Home')
            ->setRecipient((string) $customer->getFullName())
            ->setPhone('09170000000')
            ->setLine1('123 Mabini Street')
            ->setCity('Quezon City')
            ->setProvince('Metro Manila')
            ->setPostalCode('1100')
            ->setIsDefault(true)
            ->setOwner($customer);
        $this->manager->persist($address);

        return $address;
    }

    private function variant(string $key): ProductVariant
    {
        return $this->getReference(CatalogFixtures::VARIANT_PREFIX.$key, ProductVariant::class);
    }

    /**
     * @param array{User, Address} $buyer
     */
    private function place(
        array $buyer,
        string $variantKey,
        int $quantity,
        string $method,
        string $target,
        int $daysAgo,
        string $shipping,
        bool $proof = false,
        bool $approveFirst = false,
        bool $collect = false,
        bool $assembly = false,
        ?DiscountCode $voucher = null,
        ?int $deliveredDaysAgo = null,
    ): Order {
        [$customer, $address] = $buyer;
        $variant = $this->variant($variantKey);

        // The seed relies on stock being available, including variants the catalog starts as sold out.
        if ($variant->getStock() < $quantity) {
            $variant->setStock($quantity + 10);
        }

        $unit = $variant->getUnitPrice();
        $subtotal = bcmul($unit, (string) $quantity, 2);
        $shippingFee = match (true) {
            Order::SHIPPING_PICKUP === $shipping => '0.00',
            bccomp($subtotal, $this->settings->getDecimal('free_shipping_threshold'), 2) >= 0 => '0.00',
            Order::SHIPPING_EXPRESS === $shipping => $this->settings->getDecimal('express_shipping_fee'),
            default => $this->settings->getDecimal('shipping_fee'),
        };
        $assemblyFee = $assembly ? $this->settings->getDecimal('assembly_fee') : '0.00';
        $discount = null === $voucher ? '0.00' : bcdiv(bcmul($subtotal, (string) $voucher->getDiscountValue(), 4), '100', 2);
        $total = bcsub(bcadd(bcadd($subtotal, $shippingFee, 2), $assemblyFee, 2), $discount, 2);
        $placedAt = (new \DateTimeImmutable("-$daysAgo days"))->setTime(9 + $this->sequence % 8, 15);

        $order = (new Order())
            ->setOrderNumber(sprintf('HF-DEMO-%04d', ++$this->sequence))
            ->setShippingMethod($shipping)
            ->setSubtotal($subtotal)
            ->setShippingFee($shippingFee)
            ->setAssemblyFee($assemblyFee)
            ->setDiscount($discount)
            ->setTotal($total)
            ->setAssemblyRequested($assembly)
            ->setEstimatedDeliveryDate($placedAt->modify('+'.((int) $variant->getProduct()->getLeadTimeDays() + 5).' days')->setTime(0, 0))
            ->setIdempotencyKey(Uuid::v4()->toRfc4122())
            ->setCustomer($customer)
            ->setShippingAddress($address)
            ->setDiscountCode($voucher);
        $this->manager->persist($order);

        $item = (new OrderItem())
            ->setQuantity($quantity)
            ->setUnitPrice($unit)
            ->setProductName((string) $variant->getProduct()->getName())
            ->setVariantName((string) $variant->getName())
            ->setVariant($variant);
        $order->addItem($item);
        $this->manager->persist($item);

        $variant->setStock($variant->getStock() - $quantity);
        $movement = (new StockMovement())
            ->setVariant($variant)
            ->setQuantityChange(-$quantity)
            ->setMovementReason(StockMovement::REASON_ORDER)
            ->setNote('Order '.$order->getOrderNumber())
            ->setCreatedAt($placedAt);
        $this->manager->persist($movement);

        $payment = (new Payment())->setMethod($method)->setAmount($total);
        if (in_array($method, [Payment::METHOD_GCASH, Payment::METHOD_CARD], true)) {
            $payment->setReference('SIM-DEMO-'.$this->sequence);
        }
        $order->setPayment($payment);
        $this->manager->persist($payment);

        if (null !== $voucher) {
            $voucher->setTimesUsed((int) $voucher->getTimesUsed() + 1);
        }

        $this->orders->recordCreated($order, $customer);
        $this->payments->recordCreated($payment, $customer);
        $this->manager->flush();

        if ($proof) {
            $payment->setProofFile($this->copyProof());
            $this->manager->flush();
        }
        if (null !== $payment->getReference() || $approveFirst) {
            $this->payments->transition($payment, Payment::STATUS_PAID, $this->admin);
        }

        if (Order::STATUS_CANCELLED === $target) {
            $this->orders->cancel($order, 'Customer changed their mind', $customer);
        } else {
            foreach (self::PATHS[$target] as $status) {
                $this->orders->transition($order, $status, $this->admin);
            }
        }
        if ($collect) {
            $this->payments->transition($payment, Payment::STATUS_PAID, $this->admin, 'Cash collected');
        }

        $this->backdate($order, $payment, $placedAt, $deliveredDaysAgo);

        return $order;
    }

    /**
     * Seeded rows get past, spread-out timestamps instead of all saying "now".
     */
    private function backdate(Order $order, Payment $payment, \DateTimeImmutable $placedAt, ?int $deliveredDaysAgo): void
    {
        $order->setCreatedAt($placedAt);
        $payment->setCreatedAt($placedAt);

        $step = 0;
        $last = $placedAt;
        foreach ($this->manager->getRepository(OrderStatusHistory::class)->findBy(['parentOrder' => $order], ['id' => 'ASC']) as $history) {
            $last = $placedAt->modify('+'.(6 * $step++).' hours');
            $history->setCreatedAt($last);
        }

        $step = 0;
        foreach ($this->manager->getRepository(PaymentStatusHistory::class)->findBy(['payment' => $payment], ['id' => 'ASC']) as $history) {
            $history->setCreatedAt($placedAt->modify('+'.(3 * $step++).' hours'));
        }

        if (null !== $payment->getPaidAt()) {
            $payment->setPaidAt($placedAt->modify('+3 hours'));
        }
        if (null !== $order->getDeliveredAt()) {
            $order->setDeliveredAt(null === $deliveredDaysAgo ? $last : new \DateTimeImmutable("-$deliveredDaysAgo days"));
        }

        $this->manager->flush();
    }

    private function copyProof(): string
    {
        $filename = bin2hex(random_bytes(16)).'.png';
        $this->filesystem->copy($this->projectDir.'/assets/seed/proofs/sample-proof.png', $this->projectDir.'/var/uploads/proofs/'.$filename);

        return $filename;
    }

    private function reviews(User $first, User $second, User $third): void
    {
        foreach ([
            [$first, '1', 5, 'Very comfortable and well made.', true],
            [$second, '1', 4, 'Good sofa. Delivery took a few days longer than quoted.', true],
            [$third, '10', 5, 'Our whole family fits around this table.', true],
            [$first, '14', 3, 'Solid desk, but assembly took a while.', false],
            [$second, '21', 5, 'Holding up very well outdoors.', false],
        ] as $index => [$author, $product, $rating, $body, $approved]) {
            $this->manager->persist((new Review())
                ->setAuthor($author)
                ->setProduct($this->getReference(CatalogFixtures::PRODUCT_PREFIX.$product, Product::class))
                ->setRating($rating)
                ->setBody($body)
                ->setIsApproved($approved)
                ->setCreatedAt(new \DateTimeImmutable('-'.(($index + 1) * 2).' days')));
        }
    }
}
