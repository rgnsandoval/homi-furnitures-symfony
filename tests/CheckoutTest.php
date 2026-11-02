<?php

namespace App\Tests;

use App\Entity\Address;
use App\Entity\CartItem;
use App\Entity\DiscountCode;
use App\Entity\Order;
use App\Entity\Payment;
use App\Entity\PaymentStatusHistory;
use App\Entity\Product;
use App\Entity\ProductVariant;
use App\Entity\OrderStatusHistory;
use App\Entity\StockMovement;
use App\Entity\User;

class CheckoutTest extends BaseWebTest
{
    private User $user;
    private Address $address;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSettings();
        $this->user = $this->makeUser();
        $this->address = $this->makeAddress($this->user);
    }

    private function cart(ProductVariant $variant, int $quantity, ?User $owner = null): void
    {
        $this->save((new CartItem())->setOwner($owner ?? $this->user)->setVariant($variant)->setQuantity($quantity));
    }

    /**
     * @return array{token: string, key: string}
     */
    private function openCheckout(?User $user = null): array
    {
        $this->client->loginUser($user ?? $this->user);
        $crawler = $this->client->request('GET', '/checkout');
        $this->assertResponseIsSuccessful();
        $form = $crawler->filter('#checkout-form');

        return [
            'token' => $form->filter('input[name=_token]')->attr('value'),
            'key' => $form->filter('input[name=idempotencyKey]')->attr('value'),
            'csrf' => $form->attr('data-csrf'),
        ];
    }

    private function submit(array $page, array $fields = [], ?Address $address = null): void
    {
        $this->client->request('POST', '/checkout', $fields + [
            '_token' => $page['token'],
            'idempotencyKey' => $page['key'],
            'address' => ($address ?? $this->address)->getId(),
            'shippingMethod' => 'standard',
            'paymentMethod' => 'cod',
        ]);
    }

    private function preview(array $page, array $fields = []): array
    {
        $this->client->request('POST', '/checkout/preview', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $page['csrf'],
        ], content: json_encode($fields + [
            'address' => $this->address->getId(),
            'shippingMethod' => 'standard',
            'paymentMethod' => 'cod',
        ]));

        return json_decode($this->client->getResponse()->getContent(), true);
    }

    private function orders(): array
    {
        $this->em->clear();

        return $this->em->getRepository(Order::class)->findBy(['customer' => $this->user->getId()]);
    }

    private function stock(ProductVariant $variant): int
    {
        $this->em->clear();

        return $this->em->getRepository(ProductVariant::class)->find($variant->getId())->getStock();
    }

    public function testPreviewEqualsSubmitWithAllFeesAndVoucher(): void
    {
        $this->cart($this->makeVariant($this->makeProduct(null, '1000.00'), 5, '100.00'), 2);
        $this->save((new DiscountCode())->setCode('SAVE10')->setDiscountType(DiscountCode::TYPE_PERCENT)->setDiscountValue('10.00'));
        $page = $this->openCheckout();

        $fields = ['voucher' => 'save10', 'assembly' => '1', 'shippingMethod' => 'express'];
        $preview = $this->preview($page, $fields);
        $this->assertSame([], $preview['errors']);
        $this->assertSame('2200.00', $preview['subtotal']);
        $this->assertSame('1200.00', $preview['shippingFee']);
        $this->assertSame('800.00', $preview['assemblyFee']);
        $this->assertSame('220.00', $preview['discount']);
        $this->assertSame('3980.00', $preview['total']);

        $this->submit($page, $fields);
        $this->assertResponseRedirects();

        $order = $this->orders()[0];
        $this->assertSame($preview['subtotal'], $order->getSubtotal());
        $this->assertSame($preview['shippingFee'], $order->getShippingFee());
        $this->assertSame($preview['assemblyFee'], $order->getAssemblyFee());
        $this->assertSame($preview['discount'], $order->getDiscount());
        $this->assertSame($preview['total'], $order->getTotal());
        $this->assertSame($preview['estimatedDeliveryDate'], $order->getEstimatedDeliveryDate()->format('Y-m-d'));
        $this->assertSame(1, $order->getDiscountCode()->getTimesUsed());
    }

    public function testTamperedPricesAreIgnored(): void
    {
        $this->cart($this->makeVariant($this->makeProduct(null, '2000.00')), 1);
        $page = $this->openCheckout();

        $this->submit($page, ['total' => '1.00', 'subtotal' => '1.00', 'shippingFee' => '0', 'discount' => '1999', 'price' => '1']);
        $order = $this->orders()[0];
        $this->assertSame('2500.00', $order->getTotal());
        $this->assertSame('2500.00', $order->getPayment()->getAmount());
    }

    public function testShippingRules(): void
    {
        $this->cart($this->makeVariant($this->makeProduct(null, '30000.00')), 1);
        $page = $this->openCheckout();

        $this->assertSame('0.00', $this->preview($page, ['shippingMethod' => 'standard'])['shippingFee']);
        $this->assertSame('0.00', $this->preview($page, ['shippingMethod' => 'pickup'])['shippingFee']);
    }

    public function testFixedVoucherNeverTakesTotalBelowZero(): void
    {
        $this->cart($this->makeVariant($this->makeProduct(null, '1000.00')), 1);
        $this->save((new DiscountCode())->setCode('HUGE')->setDiscountType(DiscountCode::TYPE_FIXED)->setDiscountValue('99999.00'));
        $page = $this->openCheckout();

        $preview = $this->preview($page, ['voucher' => 'HUGE', 'shippingMethod' => 'pickup']);
        $this->assertSame('1000.00', $preview['discount']);
        $this->assertSame('0.00', $preview['total']);
    }

    public function testValidationRejectsBadOrders(): void
    {
        $variant = $this->makeVariant($this->makeProduct(null, '500.00'));
        $this->cart($variant, 1);
        $page = $this->openCheckout();

        $this->assertContains('The minimum order is PHP 1000.00.', $this->preview($page)['errors']);
        $this->submit($page);
        $this->assertResponseRedirects('/checkout');
        $this->assertCount(0, $this->orders());
    }

    public function testAddressOfAnotherUserIsRejected(): void
    {
        $this->cart($this->makeVariant($this->makeProduct(null, '2000.00')), 1);
        $page = $this->openCheckout();
        $foreign = $this->makeAddress($this->makeUser());

        $this->submit($page, [], $foreign);
        $this->assertResponseRedirects('/checkout');
        $this->assertCount(0, $this->orders());
    }

    public function testInactiveProductIsRejected(): void
    {
        $product = $this->makeProduct(null, '2000.00');
        $this->cart($this->makeVariant($product), 1);
        $page = $this->openCheckout();

        $this->em->getRepository(Product::class)->find($product->getId())->setStatus(Product::STATUS_ARCHIVED);
        $this->em->flush();

        $this->submit($page);
        $this->assertResponseRedirects('/checkout');
        $this->assertCount(0, $this->orders());
    }

    public function testClosedShopRejectsOrders(): void
    {
        $this->cart($this->makeVariant($this->makeProduct(null, '2000.00')), 1);
        $this->setting('shop_open', '0');
        $page = $this->openCheckout();

        $this->submit($page);
        $this->assertResponseRedirects('/checkout');
        $this->assertCount(0, $this->orders());
    }

    public function testUnverifiedUserIsSentToVerifyNotice(): void
    {
        $unverified = $this->makeUser(verified: false);
        $this->client->loginUser($unverified);
        $this->client->request('GET', '/checkout');
        $this->assertResponseRedirects('/account/verify');

        $this->client->request('GET', '/account/verify');
        $this->assertResponseIsSuccessful();
        $this->client->submitForm('Resend verification email');
        $this->assertEmailCount(1);
    }

    public function testSubmitWritesEverythingInOneOrder(): void
    {
        $variant = $this->makeVariant($this->makeProduct(null, '2000.00'), 5);
        $this->cart($variant, 2);
        $page = $this->openCheckout();

        $this->submit($page);
        $this->assertResponseRedirects();
        $this->assertSame(3, $this->stock($variant));

        $order = $this->orders()[0];
        $this->assertSame(Order::STATUS_PENDING, $order->getStatus());
        $this->assertCount(1, $order->getItems());
        $this->assertSame('2000.00', $order->getItems()[0]->getUnitPrice());
        $this->assertCount(1, $this->em->getRepository(OrderStatusHistory::class)->findBy(['parentOrder' => $order]));
        $this->assertCount(1, $this->em->getRepository(PaymentStatusHistory::class)->findBy(['payment' => $order->getPayment()]));
        $this->assertSame(-2, $this->em->getRepository(StockMovement::class)->findOneBy(['variant' => $variant->getId()])->getQuantityChange());
        $this->assertCount(0, $this->em->getRepository(CartItem::class)->findBy(['owner' => $this->user->getId()]));
    }

    public function testConfirmationEmailsAreSentAfterCommit(): void
    {
        $this->cart($this->makeVariant($this->makeProduct(null, '2000.00')), 1);
        $this->submit($this->openCheckout());
        $this->assertEmailCount(2);
    }

    public function testDoubleSubmitCreatesOneOrder(): void
    {
        $variant = $this->makeVariant($this->makeProduct(null, '2000.00'), 5);
        $this->cart($variant, 1);
        $page = $this->openCheckout();

        $this->submit($page);
        $first = $this->client->getResponse()->headers->get('Location');
        $this->submit($page);
        $this->assertResponseRedirects($first);

        $this->assertCount(1, $this->orders());
        $this->assertSame(4, $this->stock($variant));
    }

    public function testIdempotencyKeyIsStableUntilCartChanges(): void
    {
        $variant = $this->makeVariant($this->makeProduct(null, '2000.00'), 5);
        $this->cart($variant, 1);
        $first = $this->openCheckout();
        $this->assertSame($first['key'], $this->openCheckout()['key']);

        $this->em->clear();
        $this->em->getRepository(CartItem::class)->findOneBy(['owner' => $this->user->getId()])->setQuantity(2);
        $this->em->flush();
        $this->assertNotSame($first['key'], $this->openCheckout()['key']);
    }

    public function testOtherUsersKeyDoesNotRevealTheirOrder(): void
    {
        $this->cart($this->makeVariant($this->makeProduct(null, '2000.00')), 1);
        $page = $this->openCheckout();
        $this->submit($page);

        $intruder = $this->makeUser();
        $this->makeAddress($intruder);
        $this->cart($this->makeVariant($this->makeProduct(null, '2000.00')), 1, $intruder);
        $intruderPage = $this->openCheckout($intruder);
        $intruderPage['key'] = $page['key'];
        $this->submit($intruderPage);
        $this->assertResponseStatusCodeSame(403);
    }

    public function testLastUnitGoesToOneBuyerOnly(): void
    {
        $variant = $this->makeVariant($this->makeProduct(null, '2000.00'), 1);
        $other = $this->makeUser();
        $this->makeAddress($other);
        $otherAddress = $this->em->getRepository(Address::class)->findOneBy(['owner' => $other]);
        $this->cart($variant, 1);
        $this->cart($variant, 1, $other);

        $this->submit($this->openCheckout());
        $this->assertResponseRedirects();
        $this->assertSame(0, $this->stock($variant));

        // The second buyer still has the sold-out variant in their cart.
        $otherPage = $this->openCheckout($this->em->getRepository(User::class)->find($other->getId()));
        $this->submit($otherPage, [], $otherAddress);
        $this->assertResponseRedirects('/checkout');
        $this->assertSame(0, $this->stock($variant));
        $this->assertCount(1, $this->em->getRepository(Order::class)->findAll());
    }

    public function testVoucherUsageLimitIsEnforced(): void
    {
        $this->cart($this->makeVariant($this->makeProduct(null, '2000.00')), 1);
        $this->save((new DiscountCode())->setCode('ONCE')->setDiscountType(DiscountCode::TYPE_FIXED)->setDiscountValue('100.00')->setUsageLimit(1)->setTimesUsed(1));
        $page = $this->openCheckout();

        $this->submit($page, ['voucher' => 'ONCE']);
        $this->assertResponseRedirects('/checkout');
        $this->assertCount(0, $this->orders());
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('paymentOutcomes')]
    public function testEachPaymentMethodOutcome(string $method, string $status, bool $hasReference): void
    {
        $this->cart($this->makeVariant($this->makeProduct(null, '2000.00')), 1);
        $this->submit($this->openCheckout(), ['paymentMethod' => $method]);

        $payment = $this->orders()[0]->getPayment();
        $this->assertSame($method, $payment->getMethod());
        $this->assertSame($status, $payment->getStatus());
        $this->assertSame($hasReference, null !== $payment->getReference());
        $this->assertSame(Payment::STATUS_PAID === $status, null !== $payment->getPaidAt());
    }

    public static function paymentOutcomes(): array
    {
        return [
            'cod' => ['cod', 'pending', false],
            'gcash' => ['gcash', 'paid', true],
            'card' => ['card', 'paid', true],
            'bank' => ['bank', 'pending', false],
        ];
    }

    public function testCustomerSeesOnlyOwnOrders(): void
    {
        $mine = $this->makeOrder($this->user);
        $theirs = $this->makeOrder($this->makeUser());
        $this->client->loginUser($this->user);

        $crawler = $this->client->request('GET', '/orders');
        $this->assertCount(1, $crawler->filter('.order'));

        $this->client->request('GET', '/orders/'.$mine->getId());
        $this->assertResponseIsSuccessful();
        $this->client->request('GET', '/orders/'.$mine->getId().'/invoice');
        $this->assertResponseIsSuccessful();

        $this->client->request('GET', '/orders/'.$theirs->getId());
        $this->assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/orders/'.$theirs->getId().'/invoice');
        $this->assertResponseStatusCodeSame(404);
    }
}
