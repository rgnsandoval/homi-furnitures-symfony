<?php

namespace App\Tests;

use App\Entity\DiscountCode;
use App\Entity\Inquiry;
use App\Entity\Order;
use App\Entity\OrderStatusHistory;
use App\Entity\Payment;
use App\Entity\Product;
use App\Entity\Review;
use App\Repository\CategoryRepository;
use App\Repository\DiscountCodeRepository;
use App\Repository\InquiryRepository;
use App\Repository\OrderRepository;
use App\Repository\PaymentRepository;
use App\Repository\PlatformSettingRepository;
use App\Repository\ProductRepository;
use App\Repository\ProductVariantRepository;
use App\Repository\ReviewRepository;
use App\Repository\UserRepository;

class RepositoryQueriesTest extends BaseWebTest
{
    private function repo(string $class): object
    {
        return static::getContainer()->get($class);
    }

    private function range(): array
    {
        return [new \DateTimeImmutable('-1 day'), new \DateTimeImmutable('+1 day')];
    }

    public function testRevenueCountsOnlyDeliveredOrdersWithPaidPayment(): void
    {
        $user = $this->makeUser();
        $this->makeOrder($user, Order::STATUS_DELIVERED, Payment::STATUS_PAID, '1500.50');
        $this->makeOrder($user, Order::STATUS_DELIVERED, Payment::STATUS_PENDING, '999.00');
        $this->makeOrder($user, Order::STATUS_CONFIRMED, Payment::STATUS_PAID, '777.00');

        [$from, $to] = $this->range();
        $orders = $this->repo(OrderRepository::class);

        $this->assertSame('1500.50', $orders->totalRevenueBetween($from, $to));
        $this->assertSame('1500.50', $orders->averageOrderValue($from, $to));
        $this->assertCount(1, $orders->salesByDay($from, $to));
        $this->assertSame('2277.50', $this->repo(PaymentRepository::class)->totalPaidBetween($from, $to));
    }

    public function testOrderCountsAndRates(): void
    {
        $user = $this->makeUser();
        $this->makeOrder($user);
        $this->makeOrder($user, Order::STATUS_CANCELLED);
        $this->makeOrder($user, Order::STATUS_CANCELLED);
        $this->makeOrder($user, Order::STATUS_DELIVERED, Payment::STATUS_PAID);

        [$from, $to] = $this->range();
        $orders = $this->repo(OrderRepository::class);

        $this->assertSame(4, $orders->countBetween($from, $to));
        $this->assertSame(2, $orders->countByStatus()[Order::STATUS_CANCELLED]);
        $this->assertSame(0, $orders->countByStatus()[Order::STATUS_SHIPPED]);
        $this->assertSame('50.00', $orders->cancellationRate($from, $to));
        $this->assertSame('0.00', $orders->averageFulfillmentHours($from, $to));
    }

    public function testFindPendingOlderThanAndCustomerOrders(): void
    {
        $user = $this->makeUser();
        $old = $this->makeOrder($user);
        $old->setCreatedAt(new \DateTimeImmutable('-30 hours'));
        $this->makeOrder($user);
        $this->makeOrder($this->makeUser());
        $this->em->flush();

        $orders = $this->repo(OrderRepository::class);
        $pending = $orders->findPendingOlderThan(24);
        $this->assertCount(1, $pending);
        $this->assertSame($old->getId(), $pending[0]->getId());

        $this->assertCount(2, $orders->findForCustomer($user));
        $this->assertCount(0, $orders->findForCustomer($user, Order::STATUS_SHIPPED));
        $this->assertSame($old->getId(), $orders->findByIdempotencyKey($old->getIdempotencyKey())->getId());
    }

    public function testStatusHistoryIsOrderedByCreatedAtThenId(): void
    {
        $order = $this->makeOrder($this->makeUser());
        $same = new \DateTimeImmutable('2026-01-01 10:00:00');
        $first = (new OrderStatusHistory())->setToStatus('pending')->setParentOrder($order)->setCreatedAt($same);
        $second = (new OrderStatusHistory())->setToStatus('confirmed')->setParentOrder($order)->setCreatedAt($same);
        $this->save($first, $second);

        $history = $this->repo(OrderRepository::class)->statusHistory($order);
        $this->assertSame(['pending', 'confirmed'], array_map(static fn ($h) => $h->getToStatus(), $history));
    }

    public function testProductSearchFilters(): void
    {
        $room = $this->makeCategory('living-room');
        $sofas = $this->makeCategory('sofas', $room);
        $beds = $this->makeCategory('bedroom');
        $brand = $this->makeBrand();

        $sofa = $this->makeProduct($sofas, '5000.00', '4000.00', ['material' => 'Leather', 'brand' => $brand, 'widthCm' => '200.0', 'name' => 'Grand Sofa']);
        $this->makeVariant($sofa, 5, '0.00', ['color' => 'Red']);
        $bed = $this->makeProduct($beds, '9000.00', null, ['material' => 'Oak', 'widthCm' => '160.0']);
        $this->makeVariant($bed, 0);
        $this->makeProduct($beds, '1.00', null, ['status' => Product::STATUS_DRAFT]);

        $products = $this->repo(ProductRepository::class);
        $ids = static fn ($result) => array_map(static fn ($p) => $p->getId(), iterator_to_array($result));

        $this->assertSame(2, count($products->search()));
        $this->assertSame([$sofa->getId()], $ids($products->search(['room' => 'living-room'])));
        $this->assertSame([$sofa->getId()], $ids($products->search(['category' => 'sofas'])));
        $this->assertSame([$sofa->getId()], $ids($products->search(['brand' => $brand->getId()])));
        $this->assertSame([$bed->getId()], $ids($products->search(['material' => 'Oak'])));
        $this->assertSame([$sofa->getId()], $ids($products->search(['color' => 'Red'])));
        $this->assertSame([$sofa->getId()], $ids($products->search(['maxPrice' => '4500'])));
        $this->assertSame([$bed->getId()], $ids($products->search(['minPrice' => '4500'])));
        $this->assertSame([$bed->getId()], $ids($products->search(['maxWidth' => '170'])));
        $this->assertSame([$sofa->getId()], $ids($products->search(['inStock' => true])));
        $this->assertSame([$sofa->getId()], $ids($products->search(['q' => 'Grand'])));
        $this->assertSame([$sofa->getId(), $bed->getId()], $ids($products->search([], 'price_asc')));
        $this->assertSame([$bed->getId(), $sofa->getId()], $ids($products->search([], 'price_desc')));
    }

    public function testSearchIsPaginated(): void
    {
        $category = $this->makeCategory();
        for ($i = 0; $i < 3; ++$i) {
            $this->makeProduct($category);
        }

        $page = $this->repo(ProductRepository::class)->search(['category' => $category->getSlug()], 'newest', 2, 2);
        $this->assertCount(3, $page);
        $this->assertCount(1, iterator_to_array($page));
    }

    public function testFeaturedSaleAndRelated(): void
    {
        $category = $this->makeCategory();
        $featured = $this->makeProduct($category, '100.00', '80.00', ['isFeatured' => true]);
        $other = $this->makeProduct($category);
        $this->makeProduct();

        $products = $this->repo(ProductRepository::class);
        $this->assertSame([$featured->getId()], array_map(static fn ($p) => $p->getId(), $products->findFeatured()));
        $this->assertSame([$featured->getId()], array_map(static fn ($p) => $p->getId(), $products->findOnSale()));
        $this->assertSame([$other->getId()], array_map(static fn ($p) => $p->getId(), $products->findRelated($featured)));
    }

    public function testBestSellersSlowMovingAndInventoryValue(): void
    {
        $user = $this->makeUser();
        $seller = $this->makeVariant(null, 2);
        $slow = $this->makeVariant(null, 3);
        $this->makeOrder($user, Order::STATUS_CONFIRMED, Payment::STATUS_PAID, '1000.00', $seller, 4);
        $cancelled = $this->makeVariant(null, 0);
        $this->makeOrder($user, Order::STATUS_CANCELLED, Payment::STATUS_FAILED, '1000.00', $cancelled, 9);

        $products = $this->repo(ProductRepository::class);
        $best = $products->findBestSellers();
        $this->assertCount(1, $best);
        $this->assertSame($seller->getProduct()->getId(), $best[0]['product']->getId());
        $this->assertSame('4', $best[0]['sold']);

        $slowIds = array_map(static fn ($p) => $p->getId(), $products->findSlowMoving(30));
        $this->assertContains($slow->getProduct()->getId(), $slowIds);
        $this->assertNotContains($seller->getProduct()->getId(), $slowIds);

        // 2 + 3 + 0 units at the builder cost of 400.00, plus the variants made for orders.
        $this->assertSame('2000.00', $products->inventoryValue());
    }

    public function testVariantStockQueriesAndLocking(): void
    {
        $low = $this->makeVariant(null, 2);
        $out = $this->makeVariant(null, 0);
        $fine = $this->makeVariant(null, 50);

        $variants = $this->repo(ProductVariantRepository::class);
        $this->assertSame([$low->getId()], array_map(static fn ($v) => $v->getId(), $variants->findLowStock()));
        $this->assertSame([$out->getId()], array_map(static fn ($v) => $v->getId(), $variants->findOutOfStock()));

        $this->em->beginTransaction();
        $locked = $variants->lockForUpdate([$fine->getId(), $low->getId()]);
        $this->em->rollback();
        $this->assertEqualsCanonicalizing([$fine->getId(), $low->getId()], array_keys($locked));
    }

    public function testCategoryQueriesAndRevenue(): void
    {
        $root = $this->makeCategory('dining');
        $child = $this->makeCategory('tables', $root);
        $hidden = $this->makeCategory('hidden')->setIsActive(false);
        $this->em->flush();

        $categories = $this->repo(CategoryRepository::class);
        $roots = array_map(static fn ($c) => $c->getId(), $categories->findActiveRoots());
        $this->assertContains($root->getId(), $roots);
        $this->assertNotContains($child->getId(), $roots);
        $this->assertNotContains($hidden->getId(), $roots);
        $this->assertSame([$child->getId()], array_map(static fn ($c) => $c->getId(), $categories->findChildren($root)));

        $variant = $this->makeVariant($this->makeProduct($child));
        $this->makeOrder($this->makeUser(), Order::STATUS_DELIVERED, Payment::STATUS_PAID, '700.00', $variant, 2);
        [$from, $to] = $this->range();
        $revenue = $categories->revenueByCategory($from, $to);
        $this->assertSame('Tables', $revenue[0]['name']);
        $this->assertSame(0, bccomp((string) $revenue[0]['revenue'], '1400.00', 2));
    }

    public function testPaymentQueues(): void
    {
        $user = $this->makeUser();
        $bank = $this->makeOrder($user, method: Payment::METHOD_BANK);
        $bank->getPayment()->setProofFile('proof.png');
        $this->makeOrder($user, method: Payment::METHOD_BANK);
        $cod = $this->makeOrder($user, Order::STATUS_DELIVERED);
        $this->makeOrder($user);
        $this->em->flush();

        $payments = $this->repo(PaymentRepository::class);
        $this->assertSame([$bank->getPayment()->getId()], array_map(static fn ($p) => $p->getId(), $payments->findPendingBankTransfers()));
        $this->assertSame([$cod->getPayment()->getId()], array_map(static fn ($p) => $p->getId(), $payments->findCollectableCod()));
        $this->assertCount(2, $payments->findByMethodAndStatus(Payment::METHOD_BANK, Payment::STATUS_PENDING));
    }

    public function testUserQueries(): void
    {
        $repeat = $this->makeUser();
        $once = $this->makeUser();
        $this->makeOrder($repeat, total: '100.00');
        $this->makeOrder($repeat, total: '200.00');
        $this->makeOrder($once, total: '50.00');

        $users = $this->repo(UserRepository::class);
        [$from, $to] = $this->range();
        $this->assertSame(2, $users->countNewBetween($from, $to));
        $top = $users->topBuyers();
        $this->assertSame($repeat->getId(), $top[0]['user']->getId());
        $this->assertSame('300.00', $top[0]['spent']);
        $this->assertSame('50.00', $users->repeatCustomerRate());
        $this->assertCount(2, $users->findActiveCustomers(7));
    }

    public function testReviewQueries(): void
    {
        $product = $this->makeProduct();
        $approved = (new Review())->setRating(5)->setBody('Great')->setIsApproved(true)->setAuthor($this->makeUser())->setProduct($product);
        $approved2 = (new Review())->setRating(2)->setBody('Fine')->setIsApproved(true)->setAuthor($this->makeUser())->setProduct($product);
        $pending = (new Review())->setRating(1)->setBody('Bad')->setAuthor($this->makeUser())->setProduct($product);
        $this->save($approved, $approved2, $pending);

        $reviews = $this->repo(ReviewRepository::class);
        $this->assertCount(2, $reviews->findApprovedForProduct($product));
        $this->assertCount(1, $reviews->findPending());
        $this->assertSame('3.50', $reviews->averageRating($product));
        $this->assertNull($reviews->averageRating($this->makeProduct()));
    }

    public function testDiscountCodeValidity(): void
    {
        $make = static fn (string $code) => (new DiscountCode())->setCode($code)->setDiscountType(DiscountCode::TYPE_PERCENT)->setDiscountValue('10.00');
        $this->save(
            $make('GOOD'),
            $make('OFF')->setIsActive(false),
            $make('OLD')->setExpiresAt(new \DateTimeImmutable('-1 day')),
            $make('USED')->setUsageLimit(1)->setTimesUsed(1),
        );

        $codes = $this->repo(DiscountCodeRepository::class);
        $this->assertNotNull($codes->findValidByCode(' good '));
        $this->assertNull($codes->findValidByCode('OFF'));
        $this->assertNull($codes->findValidByCode('OLD'));
        $this->assertNull($codes->findValidByCode('USED'));
        $this->assertNull($codes->findValidByCode('MISSING'));
    }

    public function testInquiryAndSettings(): void
    {
        $make = static fn (string $status) => (new Inquiry())->setName('A')->setEmail('a@homi.test')->setSubject('S')->setMessage('M')->setStatus($status);
        $this->save($make(Inquiry::STATUS_NEW), $make(Inquiry::STATUS_READ), $make(Inquiry::STATUS_RESOLVED));
        $this->assertSame(2, $this->repo(InquiryRepository::class)->countUnresolved());

        $this->setting('shipping_fee', '500');
        $settings = $this->repo(PlatformSettingRepository::class);
        $this->assertSame('500', $settings->getValue('shipping_fee'));
        $this->assertSame(500, $settings->getInt('shipping_fee'));
        $this->assertSame('500.00', $settings->getDecimal('shipping_fee'));
        $this->assertNull($settings->getValue('missing'));
    }
}
