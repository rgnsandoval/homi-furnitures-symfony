<?php

namespace App\Tests;

use App\Entity\AuditLog;
use App\Entity\Brand;
use App\Entity\Category;
use App\Entity\DiscountCode;
use App\Entity\Inquiry;
use App\Entity\Order;
use App\Entity\OrderStatusHistory;
use App\Entity\Payment;
use App\Entity\PaymentStatusHistory;
use App\Entity\Product;
use App\Entity\ProductImage;
use App\Entity\ProductVariant;
use App\Entity\Review;
use App\Entity\StockMovement;
use App\Entity\User;

class AdminTest extends BaseWebTest
{
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->makeAdmin();
    }

    private function loginAdmin(): void
    {
        $this->client->loginUser($this->em->getRepository(User::class)->find($this->admin->getId()));
    }

    private function auditActions(): array
    {
        return array_map(static fn (AuditLog $log) => $log->getAction(), $this->em->getRepository(AuditLog::class)->findAll());
    }

    public function testAdminAreaIsForAdminsOnly(): void
    {
        $this->client->request('GET', '/admin');
        $this->assertResponseRedirects('/login');

        $this->client->loginUser($this->makeUser());
        $this->client->request('GET', '/admin');
        $this->assertResponseStatusCodeSame(403);

        $this->loginAdmin();
        $this->client->request('GET', '/admin');
        $this->assertResponseIsSuccessful();
    }

    public function testDashboardShowsKpis(): void
    {
        $user = $this->makeUser();
        $variant = $this->makeVariant(null, 2);
        $this->makeOrder($user, Order::STATUS_DELIVERED, Payment::STATUS_PAID, '1234.50', $variant);
        $this->makeVariant(null, 0);
        $old = $this->makeOrder($user);
        $old->setCreatedAt(new \DateTimeImmutable('-3 days'));
        $this->em->flush();

        $this->loginAdmin();
        $crawler = $this->client->request('GET', '/admin');
        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('₱1,234.50', $crawler->filter('#revenue')->text());
        $this->assertStringContainsString($old->getOrderNumber(), $crawler->text());

        $kpis = $crawler->filter('#kpis')->text();
        $this->assertStringContainsString('Revenue', $kpis);
        $this->assertStringContainsString('₱1,234.50', $kpis);
        $this->assertStringContainsString('Cancellation rate', $kpis);
        $this->assertCount(1, $crawler->filter('#out-of-stock li a'));
        $this->assertCount(30, $crawler->filter('[aria-label^="Bar chart"] > div'));
    }

    public function testCategoryAndBrandCrud(): void
    {
        $this->loginAdmin();

        $crawler = $this->client->request('GET', '/admin/category/new');
        $this->client->submit($crawler->selectButton('Save')->form([
            'category[name]' => 'Bedroom',
            'category[slug]' => 'bedroom',
            'category[displayOrder]' => '1',
        ]));
        $this->assertResponseRedirects('/admin/category');
        $this->assertNotNull($this->em->getRepository(Category::class)->findOneBy(['slug' => 'bedroom']));

        $crawler = $this->client->request('GET', '/admin/category/new');
        $this->client->submit($crawler->selectButton('Save')->form([
            'category[name]' => 'Duplicate',
            'category[slug]' => 'bedroom',
            'category[displayOrder]' => '1',
        ]));
        $this->assertResponseStatusCodeSame(422);

        $crawler = $this->client->request('GET', '/admin/brand/new');
        $this->client->submit($crawler->selectButton('Save')->form(['brand[name]' => 'Narra Works']));
        $this->assertResponseRedirects('/admin/brand');
        $this->assertNotNull($this->em->getRepository(Brand::class)->findOneBy(['name' => 'Narra Works']));

        $this->client->request('GET', '/admin/category');
        $this->assertResponseIsSuccessful();
    }

    public function testCategoryInUseCannotBeDeleted(): void
    {
        $category = $this->makeCategory('in-use');
        $this->makeProduct($category);
        $this->loginAdmin();

        $crawler = $this->client->request('GET', '/admin/category/'.$category->getId());
        $this->client->submit($crawler->selectButton('Delete')->form());
        $this->client->followRedirect();
        $this->assertSelectorTextContains('.flash-error', 'in use');
    }

    public function testProductCrudWithImages(): void
    {
        $category = $this->makeCategory();
        $this->loginAdmin();

        $png = sys_get_temp_dir().'/product-'.uniqid().'.png';
        file_put_contents($png, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='));

        $crawler = $this->client->request('GET', '/admin/product/new');
        $form = $crawler->selectButton('Save')->form([
            'product[name]' => 'Oak Table',
            'product[sku]' => 'OAK-1',
            'product[slug]' => 'oak-table',
            'product[description]' => 'A table.',
            'product[price]' => '4500.00',
            'product[costPrice]' => '2000.00',
            'product[status]' => 'active',
            'product[material]' => 'Oak',
            'product[leadTimeDays]' => '5',
            'product[specifications]' => '{"Seats": "6"}',
            'product[category]' => (string) $category->getId(),
        ]);
        $form['product[imageFile]']->upload($png);
        $form['product[galleryFiles]'][0]->upload($png);
        $this->client->submit($form);
        $this->assertResponseRedirects('/admin/product');
        unlink($png);

        $this->em->clear();
        $product = $this->em->getRepository(Product::class)->findOneBy(['sku' => 'OAK-1']);
        $this->assertSame(['Seats' => '6'], $product->getSpecifications());
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}\.png$/', $product->getImage());
        $this->assertCount(1, $product->getImages());

        $dir = static::getContainer()->getParameter('kernel.project_dir').'/public/uploads/products/';
        $this->assertFileExists($dir.$product->getImage());
        unlink($dir.$product->getImage());

        $crawler = $this->client->request('GET', '/admin/product/'.$product->getId().'/edit');
        $this->assertResponseIsSuccessful();
        $this->client->submit($crawler->selectButton('Delete image')->form());
        $this->em->clear();
        $this->assertCount(0, $this->em->getRepository(ProductImage::class)->findAll());
    }

    public function testProductWithOrdersCannotBeDeleted(): void
    {
        $variant = $this->makeVariant();
        $this->makeOrder($this->makeUser(), variant: $variant);
        $this->loginAdmin();

        $crawler = $this->client->request('GET', '/admin/product/'.$variant->getProduct()->getId());
        $this->client->submit($crawler->selectButton('Delete')->form());
        $this->client->followRedirect();
        $this->assertSelectorTextContains('.flash-error', 'Archive it instead');
    }

    public function testVariantEditCannotChangeStockDirectly(): void
    {
        $variant = $this->makeVariant(null, 7);
        $this->loginAdmin();

        $crawler = $this->client->request('GET', '/admin/product/variant/'.$variant->getId().'/edit');
        $this->assertResponseIsSuccessful();
        $this->assertNotNull($crawler->filter('input[name="product_variant[stock]"]')->attr('disabled'));
    }

    public function testStockAdjustmentWritesMovementAndAudit(): void
    {
        $variant = $this->makeVariant(null, 5);
        $this->loginAdmin();

        $crawler = $this->client->request('GET', '/admin/stock/'.$variant->getId());
        $this->client->submit($crawler->selectButton('Save adjustment')->form([
            'stock_adjustment_form[quantityChange]' => '-2',
            'stock_adjustment_form[movementReason]' => 'damaged',
            'stock_adjustment_form[note]' => 'Scratched',
        ]));
        $this->assertResponseRedirects();

        $this->em->clear();
        $this->assertSame(3, $this->em->getRepository(ProductVariant::class)->find($variant->getId())->getStock());
        $movement = $this->em->getRepository(StockMovement::class)->findOneBy(['variant' => $variant->getId()]);
        $this->assertSame(-2, $movement->getQuantityChange());
        $this->assertSame('damaged', $movement->getMovementReason());
        $this->assertSame($this->admin->getId(), $movement->getAdminUser()->getId());
        $this->assertContains('stock.adjust', $this->auditActions());
    }

    public function testStockCannotGoBelowZero(): void
    {
        $variant = $this->makeVariant(null, 1);
        $this->loginAdmin();

        $crawler = $this->client->request('GET', '/admin/stock/'.$variant->getId());
        $this->client->submit($crawler->selectButton('Save adjustment')->form([
            'stock_adjustment_form[quantityChange]' => '-5',
            'stock_adjustment_form[movementReason]' => 'correction',
        ]));
        $this->assertSelectorTextContains('body', 'Stock cannot go below zero');
        $this->em->clear();
        $this->assertSame(1, $this->em->getRepository(ProductVariant::class)->find($variant->getId())->getStock());
        $this->assertCount(0, $this->em->getRepository(StockMovement::class)->findAll());
    }

    public function testDiscountCodeIsStoredUppercase(): void
    {
        $this->loginAdmin();
        $crawler = $this->client->request('GET', '/admin/discount/code/new');
        $this->client->submit($crawler->selectButton('Save')->form([
            'discount_code[code]' => 'welcome5',
            'discount_code[discountType]' => 'percent',
            'discount_code[discountValue]' => '5.00',
        ]));
        $this->assertResponseRedirects();
        $this->assertNotNull($this->em->getRepository(DiscountCode::class)->findOneBy(['code' => 'WELCOME5']));
    }

    public function testOrderManagementTransitionsAndAudit(): void
    {
        $order = $this->makeOrder($this->makeUser());
        $this->loginAdmin();

        $crawler = $this->client->request('GET', '/admin/orders');
        $this->assertCount(1, $crawler->filter('.order'));
        $crawler = $this->client->request('GET', '/admin/orders?status=shipped');
        $this->assertCount(0, $crawler->filter('.order'));

        $crawler = $this->client->request('GET', '/admin/orders/'.$order->getId());
        $this->client->submit($crawler->selectButton('Mark confirmed')->form());
        $crawler = $this->client->followRedirect();
        $this->assertSelectorTextContains('#order-status', 'confirmed');

        $this->client->submit($crawler->selectButton('Save details')->form(['trackingNumber' => 'TRK123', 'internalNote' => 'Fragile']));
        $crawler = $this->client->followRedirect();
        $this->em->clear();
        $saved = $this->em->getRepository(Order::class)->find($order->getId());
        $this->assertSame('TRK123', $saved->getTrackingNumber());

        $this->client->submit($crawler->selectButton('Cancel order')->form(['reason' => 'Damaged in warehouse']));
        $this->em->clear();
        $saved = $this->em->getRepository(Order::class)->find($order->getId());
        $this->assertSame(Order::STATUS_CANCELLED, $saved->getStatus());
        $this->assertSame('Damaged in warehouse', $saved->getCancelReason());
        $this->assertContains('order.transition', $this->auditActions());
        $this->assertContains('order.cancel', $this->auditActions());
    }

    public function testFraudOrderIsCancelledAndCustomerBlockedThenDeleted(): void
    {
        $customer = $this->makeUser();
        $variant = $this->makeVariant(null, 5);
        $order = $this->makeOrder($customer, Order::STATUS_PENDING, Payment::STATUS_PENDING, '2500.00', $variant, 2);
        $variant->setStock(3);
        $this->em->flush();
        $id = $order->getId();
        $number = $order->getOrderNumber();
        $paymentId = $order->getPayment()->getId();
        $this->loginAdmin();

        $crawler = $this->client->request('GET', '/admin/orders/'.$id);
        $this->assertStringContainsString('Only cancelled orders can be deleted', $crawler->filter('#danger-zone')->text());
        $this->client->submit($crawler->selectButton('Flag as fraud and cancel')->form(['note' => 'Fake bank slip']));
        $crawler = $this->client->followRedirect();

        $this->em->clear();
        $saved = $this->em->getRepository(Order::class)->find($id);
        $this->assertSame(Order::STATUS_CANCELLED, $saved->getStatus());
        $this->assertSame('Fraud: Fake bank slip', $saved->getCancelReason());
        $this->assertSame(Payment::STATUS_FAILED, $saved->getPayment()->getStatus());
        $this->assertSame(5, $this->em->getRepository(ProductVariant::class)->find($variant->getId())->getStock());
        $this->assertFalse($this->em->getRepository(User::class)->find($customer->getId())->isActive());
        $this->assertContains('order.fraud', $this->auditActions());

        // A wrong confirmation keeps the order.
        $this->client->submit($crawler->selectButton('Delete order permanently')->form(['confirm' => 'nope']));
        $this->client->followRedirect();
        $this->assertSelectorTextContains('.flash-error', 'Type the order number');
        $this->em->clear();
        $this->assertNotNull($this->em->getRepository(Order::class)->find($id));

        $crawler = $this->client->request('GET', '/admin/orders/'.$id);
        $this->client->submit($crawler->selectButton('Delete order permanently')->form(['confirm' => $number]));
        $this->assertResponseRedirects('/admin/orders');
        $this->em->clear();
        $this->assertNull($this->em->getRepository(Order::class)->find($id));
        $this->assertNull($this->em->getRepository(Payment::class)->find($paymentId));
        $this->assertSame([], $this->em->getRepository(OrderStatusHistory::class)->findBy(['parentOrder' => $id]));
        $this->assertSame([], $this->em->getRepository(PaymentStatusHistory::class)->findBy(['payment' => $paymentId]));
        $this->assertContains('order.delete', $this->auditActions());
    }

    public function testOrdersHoldingMoneyOrNotCancelledCannotBeDeleted(): void
    {
        $customer = $this->makeUser();
        $open = $this->makeOrder($customer);
        $paid = $this->makeOrder($customer, Order::STATUS_CANCELLED, Payment::STATUS_PAID);
        $this->loginAdmin();

        foreach ([$open, $paid] as $order) {
            $crawler = $this->client->request('GET', '/admin/orders/'.$order->getId());
            $token = $crawler->filter('form input[name=_token]')->first()->attr('value');
            $this->client->request('POST', '/admin/orders/'.$order->getId().'/delete', ['_token' => $token, 'confirm' => $order->getOrderNumber()]);
            $this->client->followRedirect();
            $this->assertSelectorExists('.flash-error');
        }

        $this->em->clear();
        $this->assertNotNull($this->em->getRepository(Order::class)->find($open->getId()));
        $this->assertNotNull($this->em->getRepository(Order::class)->find($paid->getId()));
        $this->assertNotContains('order.delete', $this->auditActions());
    }

    public function testInvalidTransitionShowsErrorInsteadOfCrashing(): void
    {
        $order = $this->makeOrder($this->makeUser());
        $this->loginAdmin();
        $crawler = $this->client->request('GET', '/admin/orders/'.$order->getId());
        $token = $crawler->filter('form input[name=_token]')->first()->attr('value');

        $this->client->request('POST', '/admin/orders/'.$order->getId().'/transition', ['_token' => $token, 'to' => 'delivered']);
        $this->client->followRedirect();
        $this->assertSelectorTextContains('.flash-error', 'cannot become delivered');
    }

    public function testBankProofApproveAndRejectWriteAudit(): void
    {
        $user = $this->makeUser();
        $approved = $this->makeOrder($user, method: Payment::METHOD_BANK);
        $approved->getPayment()->setProofFile('a.png');
        $rejected = $this->makeOrder($user, method: Payment::METHOD_BANK);
        $rejected->getPayment()->setProofFile('b.png');
        $this->em->flush();
        $this->loginAdmin();

        $crawler = $this->client->request('GET', '/admin/payments/queue');
        $this->assertCount(2, $crawler->filter('#bank-queue tr'));
        $this->client->submit($crawler->filter('#bank-queue tr')->eq(0)->selectButton('Approve')->form());
        $crawler = $this->client->followRedirect();
        $this->client->submit($crawler->filter('#bank-queue tr')->eq(0)->selectButton('Reject')->form());

        $this->em->clear();
        $repo = $this->em->getRepository(Payment::class);
        $this->assertSame(Payment::STATUS_PAID, $repo->find($approved->getPayment()->getId())->getStatus());
        $this->assertSame(Payment::STATUS_FAILED, $repo->find($rejected->getPayment()->getId())->getStatus());
        $this->assertContains('payment.approve', $this->auditActions());
        $this->assertContains('payment.reject', $this->auditActions());
    }

    public function testCodCollectionOnlyAfterDelivery(): void
    {
        $user = $this->makeUser();
        $delivered = $this->makeOrder($user, Order::STATUS_DELIVERED);
        $shipped = $this->makeOrder($user, Order::STATUS_SHIPPED);
        $this->loginAdmin();

        $crawler = $this->client->request('GET', '/admin/payments/queue');
        $this->assertCount(1, $crawler->filter('#cod-queue form'));
        $this->client->submit($crawler->selectButton('Mark collected')->form());

        $this->client->followRedirect();
        $this->assertSelectorTextContains('.flash-success', 'Cash collected');

        $this->em->clear();
        $repo = $this->em->getRepository(Payment::class);
        $this->assertSame(Payment::STATUS_PAID, $repo->find($delivered->getPayment()->getId())->getStatus());
        $this->assertSame(Payment::STATUS_PENDING, $repo->find($shipped->getPayment()->getId())->getStatus());
        $this->assertContains('payment.collect', $this->auditActions());
    }

    public function testRefundWritesAudit(): void
    {
        $order = $this->makeOrder($this->makeUser(), Order::STATUS_CONFIRMED, Payment::STATUS_PAID, method: Payment::METHOD_GCASH);
        $this->loginAdmin();

        $crawler = $this->client->request('GET', '/admin/payments/queue');
        $this->client->submit($crawler->selectButton('Refund')->form());

        $this->em->clear();
        $this->assertSame(Payment::STATUS_REFUNDED, $this->em->getRepository(Payment::class)->find($order->getPayment()->getId())->getStatus());
        $this->assertContains('payment.refund', $this->auditActions());
    }

    public function testPaymentsListIsReadOnly(): void
    {
        $this->makeOrder($this->makeUser());
        $this->loginAdmin();
        $crawler = $this->client->request('GET', '/admin/payments');
        $this->assertCount(1, $crawler->filter('.payment'));
        $this->assertCount(0, $crawler->filter('.payment form'));
    }

    public function testUserDeactivationAndSelfProtection(): void
    {
        $user = $this->makeUser();
        $this->loginAdmin();

        $crawler = $this->client->request('GET', '/admin/users?q='.$user->getEmail());
        $this->client->submit($crawler->selectButton('Deactivate')->form());
        $this->em->clear();
        $this->assertFalse($this->em->getRepository(User::class)->find($user->getId())->isActive());
        $this->assertContains('user.deactivate', $this->auditActions());

        $crawler = $this->client->request('GET', '/admin/users?q='.$this->admin->getEmail());
        $this->client->submit($crawler->selectButton('Deactivate')->form());
        $this->em->clear();
        $this->assertTrue($this->em->getRepository(User::class)->find($this->admin->getId())->isActive());
    }

    public function testReviewModeration(): void
    {
        $product = $this->makeProduct();
        $this->makeVariant($product);
        $keep = (new Review())->setRating(5)->setBody('Keep me')->setAuthor($this->makeUser())->setProduct($product);
        $drop = (new Review())->setRating(1)->setBody('Drop me')->setAuthor($this->makeUser())->setProduct($product);
        $this->save($keep, $drop);
        $this->loginAdmin();

        $crawler = $this->client->request('GET', '/admin/reviews');
        $this->client->submit($crawler->selectButton('Approve')->form());
        $crawler = $this->client->followRedirect();
        $this->client->submit($crawler->selectButton('Delete')->form());

        $this->em->clear();
        $reviews = $this->em->getRepository(Review::class)->findAll();
        $this->assertCount(1, $reviews);
        $this->assertTrue($reviews[0]->isApproved());

        $this->client->request('GET', '/product/'.$product->getSlug());
        $this->assertSelectorTextContains('body', 'Keep me');
    }

    public function testInquiryStatusCanBeUpdated(): void
    {
        $inquiry = (new Inquiry())->setName('Ana')->setEmail('ana@homi.test')->setSubject('Hi')->setMessage('Hello');
        $this->save($inquiry);
        $this->loginAdmin();

        $crawler = $this->client->request('GET', '/admin/inquiries');
        $this->client->submit($crawler->selectButton('Save status')->form(['status' => 'resolved']));
        $this->em->clear();
        $this->assertSame(Inquiry::STATUS_RESOLVED, $this->em->getRepository(Inquiry::class)->find($inquiry->getId())->getStatus());
    }

    public function testSalesReportAndCsvExport(): void
    {
        $this->makeOrder($this->makeUser(), Order::STATUS_DELIVERED, Payment::STATUS_PAID, '999.99');
        $this->makeOrder($this->makeUser(), Order::STATUS_DELIVERED, Payment::STATUS_PENDING, '5000.00');
        $this->loginAdmin();
        $today = date('Y-m-d');

        $this->client->request('GET', "/admin/reports?from=$today&to=$today");
        $this->assertSelectorTextContains('#report-revenue', '₱999.99');

        $this->client->request('GET', "/admin/reports?from=not-a-date&to=also-bad");
        $this->assertResponseIsSuccessful();

        ob_start();
        $this->client->request('GET', "/admin/reports/export?from=$today&to=$today");
        $csv = ob_get_clean() ?: $this->client->getInternalResponse()->getContent();
        $this->assertResponseHeaderSame('content-type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('Day,Orders,Revenue', $csv);
        $this->assertStringContainsString("$today,1,999.99", $csv);
    }

    public function testSettingsAndAuditLogPagesLoad(): void
    {
        $this->seedSettings();
        $this->loginAdmin();

        $this->client->request('GET', '/admin/audit');
        $this->assertResponseIsSuccessful();
        $crawler = $this->client->request('GET', '/admin');
        $link = $crawler->selectLink('Settings')->link();
        $crawler = $this->client->click($link);
        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('shipping_fee', $crawler->text());
    }
}
