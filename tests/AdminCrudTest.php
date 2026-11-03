<?php

namespace App\Tests;

use App\Entity\Address;
use App\Entity\AuditLog;
use App\Entity\Inquiry;
use App\Entity\Order;
use App\Entity\Payment;
use App\Entity\ProductVariant;
use App\Entity\ReturnRequest;
use App\Entity\Review;
use App\Entity\StockMovement;
use App\Entity\User;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Create, read, update and delete for the admin areas that are not make:crud screens:
 * users and their addresses, orders placed by staff, reviews, inquiries and returns.
 */
class AdminCrudTest extends BaseWebTest
{
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSettings();
        $this->admin = $this->makeAdmin();
        $this->client->loginUser($this->admin);
    }

    /**
     * @return list<string>
     */
    private function auditActions(): array
    {
        return array_map(static fn (AuditLog $log) => $log->getAction(), $this->em->getRepository(AuditLog::class)->findAll());
    }

    private function freshUser(int $id): ?User
    {
        $this->em->clear();

        return $this->em->getRepository(User::class)->find($id);
    }

    public function testAdminCreatesEditsAndDeletesAUser(): void
    {
        $crawler = $this->client->request('GET', '/admin/users/new');
        $this->client->submit($crawler->selectButton('Create user')->form([
            'admin_user[fullName]' => 'Rosa Diaz',
            'admin_user[email]' => 'rosa@homi.test',
            'admin_user[plainPassword]' => 'correct-horse-9',
        ]));
        $this->assertResponseRedirects();
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => 'rosa@homi.test']);
        $this->assertNotNull($user);
        $this->assertNotContains('ROLE_ADMIN', $user->getRoles());
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $this->assertTrue($hasher->isPasswordValid($user, 'correct-horse-9'));
        $id = (int) $user->getId();

        $crawler = $this->client->request('GET', '/admin/users/'.$id.'/edit');
        $this->client->submit($crawler->selectButton('Save user')->form([
            'admin_user[fullName]' => 'Rosa D. Diaz',
            'admin_user[admin]' => true,
            'admin_user[plainPassword]' => '',
        ]));
        $user = $this->freshUser($id);
        $this->assertSame('Rosa D. Diaz', $user->getFullName());
        $this->assertContains('ROLE_ADMIN', $user->getRoles());
        $this->assertTrue($hasher->isPasswordValid($user, 'correct-horse-9'), 'A blank password keeps the old one.');

        $crawler = $this->client->request('GET', '/admin/users/'.$id);
        $this->client->submit($crawler->selectButton('Delete account')->form());
        $this->assertResponseRedirects('/admin/users');
        $this->assertNull($this->freshUser($id));
        $this->assertSame(['user.create', 'user.update', 'user.delete'], $this->auditActions());
    }

    public function testUsersWithOrdersCanOnlyBeDeactivated(): void
    {
        $customer = $this->makeUser();
        // Take the delete token while the account can still be deleted, then give it an order.
        $crawler = $this->client->request('GET', '/admin/users/'.$customer->getId());
        $token = $crawler->filter('#danger-zone input[name=_token]')->attr('value');
        $this->makeOrder($customer);

        $crawler = $this->client->request('GET', '/admin/users/'.$customer->getId());
        $this->assertStringContainsString('1 orders', $crawler->filter('#danger-zone')->text());
        $this->assertCount(0, $crawler->filter('#danger-zone form'));

        $this->client->request('POST', '/admin/users/'.$customer->getId().'/delete', ['_token' => $token]);
        $this->client->followRedirect();
        $this->assertSelectorTextContains('.flash-error', 'Deactivate it instead');
        $this->assertNotNull($this->freshUser((int) $customer->getId()));
    }

    public function testAdminCannotDeleteOrDemoteThemselves(): void
    {
        $crawler = $this->client->request('GET', '/admin/users/'.$this->admin->getId().'/edit');
        $this->assertNotNull($crawler->filter('#admin_user_admin')->attr('disabled'));

        $crawler = $this->client->request('GET', '/admin/users/'.$this->admin->getId());
        $this->assertStringContainsString('cannot delete your own account', $crawler->filter('#danger-zone')->text());
    }

    public function testAdminManagesACustomersAddresses(): void
    {
        $customer = $this->makeUser();

        $crawler = $this->client->request('GET', '/admin/users/'.$customer->getId().'/addresses/new');
        $this->client->submit($crawler->selectButton('Save address')->form([
            'address[label]' => 'Office',
            'address[recipient]' => 'Test User',
            'address[phone]' => '09171234567',
            'address[line1]' => '5 Ayala Ave',
            'address[city]' => 'Makati',
            'address[province]' => 'Metro Manila',
            'address[postalCode]' => '1226',
        ]));
        $address = $this->em->getRepository(Address::class)->findOneBy(['owner' => $customer]);
        $this->assertSame('Office', $address->getLabel());
        $this->assertTrue($address->isDefault(), 'The first address becomes the default.');

        $crawler = $this->client->request('GET', '/admin/addresses/'.$address->getId().'/edit');
        $this->client->submit($crawler->selectButton('Save address')->form(['address[city]' => 'Taguig']));
        $this->em->clear();
        $this->assertSame('Taguig', $this->em->getRepository(Address::class)->find($address->getId())->getCity());

        $crawler = $this->client->request('GET', '/admin/users/'.$customer->getId());
        $this->client->submit($crawler->filter('form[action$="/delete"]')->first()->form());
        $this->em->clear();
        $this->assertNull($this->em->getRepository(Address::class)->find($address->getId()));
    }

    public function testAdminPlacesAnOrderForACustomerAtShopPrices(): void
    {
        $customer = $this->makeUser([], false);
        $address = $this->makeAddress($customer);
        $variant = $this->makeVariant(null, 5);

        $crawler = $this->client->request('GET', '/admin/orders/new?customer='.$customer->getId());
        $this->assertResponseIsSuccessful();
        $form = $crawler->filter('#admin-order-form');
        $this->client->request('POST', '/admin/orders/new?customer='.$customer->getId(), [
            '_token' => $form->filter('input[name=_token]')->attr('value'),
            'customer' => $customer->getId(),
            'idempotencyKey' => $form->filter('input[name=idempotencyKey]')->attr('value'),
            'sku' => [$variant->getSku().' · whatever the picker showed', '', $variant->getSku()],
            'quantity' => ['1', '1', '1'],
            'address' => $address->getId(),
            'shippingMethod' => Order::SHIPPING_STANDARD,
            'paymentMethod' => Payment::METHOD_COD,
            'unitPrice' => '1.00',
        ]);
        $this->assertResponseRedirects();

        $this->em->clear();
        $order = $this->em->getRepository(Order::class)->findOneBy(['customer' => $customer->getId()]);
        $this->assertNotNull($order, 'Staff orders skip the email-verified gate.');
        $this->assertCount(1, $order->getItems(), 'Repeated SKUs merge into one line.');
        $this->assertSame(2, $order->getItems()->first()->getQuantity());
        $this->assertSame('2000.00', $order->getSubtotal(), 'Prices come from the catalog, not the request.');
        $this->assertSame('2500.00', $order->getTotal());
        $this->assertSame(3, $this->em->getRepository(ProductVariant::class)->find($variant->getId())->getStock());
        $movement = $this->em->getRepository(StockMovement::class)->findOneBy(['variant' => $variant->getId()]);
        $this->assertSame($this->admin->getId(), $movement->getAdminUser()->getId());
        $this->assertContains('order.create', $this->auditActions());
    }

    public function testAdminOrderRejectsUnknownSkuAndTooMuchQuantity(): void
    {
        $customer = $this->makeUser();
        $address = $this->makeAddress($customer);
        $variant = $this->makeVariant(null, 1);

        foreach ([['NOPE-1', '1', 'No product variant has the SKU NOPE-1'], [(string) $variant->getSku(), '3', 'Only 1 of']] as [$sku, $qty, $message]) {
            $crawler = $this->client->request('GET', '/admin/orders/new?customer='.$customer->getId());
            $form = $crawler->filter('#admin-order-form');
            $crawler = $this->client->request('POST', '/admin/orders/new?customer='.$customer->getId(), [
                '_token' => $form->filter('input[name=_token]')->attr('value'),
                'customer' => $customer->getId(),
                'idempotencyKey' => $form->filter('input[name=idempotencyKey]')->attr('value'),
                'sku' => [$sku],
                'quantity' => [$qty],
                'address' => $address->getId(),
                'shippingMethod' => Order::SHIPPING_STANDARD,
                'paymentMethod' => Payment::METHOD_COD,
            ]);
            $this->assertResponseStatusCodeSame(422);
            $this->assertStringContainsString($message, $crawler->filter('.flash-error')->text());
        }

        $this->assertSame(0, $this->em->getRepository(Order::class)->count(['customer' => $customer]));
    }

    public function testOrderDetailsCanBeEdited(): void
    {
        $customer = $this->makeUser();
        $order = $this->makeOrder($customer);
        $other = $this->makeAddress($customer, false);
        $stranger = $this->makeAddress($this->makeUser(), false);

        $crawler = $this->client->request('GET', '/admin/orders/'.$order->getId());
        $this->client->submit($crawler->selectButton('Save details')->form([
            'address' => $other->getId(),
            'estimatedDeliveryDate' => '2030-01-15',
            'notes' => 'Leave at the guard house',
        ]));
        $this->em->clear();
        $saved = $this->em->getRepository(Order::class)->find($order->getId());
        $this->assertSame($other->getId(), $saved->getShippingAddress()->getId());
        $this->assertSame('2030-01-15', $saved->getEstimatedDeliveryDate()->format('Y-m-d'));
        $this->assertSame('Leave at the guard house', $saved->getNotes());

        // Another customer's address is ignored.
        $crawler = $this->client->request('GET', '/admin/orders/'.$order->getId());
        $form = $crawler->selectButton('Save details')->form();
        $this->client->request('POST', $form->getUri(), ['_token' => $form['_token']->getValue(), 'address' => $stranger->getId()]);
        $this->em->clear();
        $this->assertSame($other->getId(), $this->em->getRepository(Order::class)->find($order->getId())->getShippingAddress()->getId());
    }

    public function testReviewCanBeEditedAndFiltered(): void
    {
        $review = (new Review())->setRating(2)->setBody('Wobbly, call me at 0917')->setAuthor($this->makeUser())->setProduct($this->makeProduct());
        $this->save($review);

        $crawler = $this->client->request('GET', '/admin/reviews/'.$review->getId().'/edit');
        $this->client->submit($crawler->selectButton('Save review')->form([
            'admin_review[rating]' => '3',
            'admin_review[body]' => 'Wobbly.',
            'admin_review[isApproved]' => true,
        ]));
        $this->em->clear();
        $saved = $this->em->getRepository(Review::class)->find($review->getId());
        $this->assertSame(3, $saved->getRating());
        $this->assertSame('Wobbly.', $saved->getBody());
        $this->assertTrue($saved->isApproved());

        $this->client->request('GET', '/admin/reviews');
        $this->assertSelectorNotExists('.review');
        $this->client->request('GET', '/admin/reviews?show=approved');
        $this->assertSelectorTextContains('.review', 'Wobbly.');
    }

    public function testInquiryCanBeOpenedAndDeleted(): void
    {
        $inquiry = (new Inquiry())->setName('Ana')->setEmail('ana@homi.test')->setSubject('Spam?')->setMessage('Buy followers');
        $this->save($inquiry);

        $crawler = $this->client->request('GET', '/admin/inquiries/'.$inquiry->getId());
        $this->assertSelectorTextContains('h1', 'Spam?');
        $this->client->submit($crawler->selectButton('Delete')->form());
        $this->assertResponseRedirects('/admin/inquiries');
        $this->em->clear();
        $this->assertNull($this->em->getRepository(Inquiry::class)->find($inquiry->getId()));
        $this->assertContains('inquiry.delete', $this->auditActions());
    }

    public function testOnlyUndecidedOrRejectedReturnsCanBeDeleted(): void
    {
        $customer = $this->makeUser();
        $order = $this->makeOrder($customer, Order::STATUS_DELIVERED, Payment::STATUS_PAID);
        $requested = (new ReturnRequest())->setReturnReason('Scratched')->setParentOrder($order)->setCustomer($customer);
        $approved = (new ReturnRequest())->setReturnReason('Wrong colour')->setParentOrder($order)->setCustomer($customer)->setStatus(ReturnRequest::STATUS_APPROVED);
        $this->save($requested, $approved);

        $crawler = $this->client->request('GET', '/admin/returns/'.$approved->getId());
        $this->assertCount(0, $crawler->selectButton('Delete return request'));
        $token = $crawler->filter('form input[name=_token]')->first()->attr('value');
        $this->client->request('POST', '/admin/returns/'.$approved->getId().'/delete', ['_token' => $token]);
        $this->client->followRedirect();
        $this->assertSelectorTextContains('.flash-error', 'kept with the refund record');

        $crawler = $this->client->request('GET', '/admin/returns/'.$requested->getId());
        $this->client->submit($crawler->selectButton('Delete return request')->form());
        $this->em->clear();
        $this->assertNull($this->em->getRepository(ReturnRequest::class)->find($requested->getId()));
        $this->assertNotNull($this->em->getRepository(ReturnRequest::class)->find($approved->getId()));

        $this->client->request('GET', '/admin/returns?show=all');
        $this->assertSelectorCount(1, '.return');
    }
}
