<?php

namespace App\Tests;

use App\Entity\AuditLog;
use App\Entity\Order;
use App\Entity\Payment;
use App\Entity\ProductVariant;
use App\Entity\ReturnRequest;
use App\Entity\User;

class ReturnFlowTest extends BaseWebTest
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSettings();
    }

    private function requestReturn(Order $order, ?string $photo = null): void
    {
        $crawler = $this->client->request('GET', '/orders/'.$order->getId().'/return');
        $form = $crawler->selectButton('Request return')->form(['return_request_form[returnReason]' => 'Arrived scratched']);
        if (null !== $photo) {
            $form['return_request_form[photoFile]']->upload($photo);
        }
        $this->client->submit($form);
    }

    private function returnFor(Order $order): ?ReturnRequest
    {
        $this->em->clear();

        return $this->em->getRepository(ReturnRequest::class)->findOneBy(['parentOrder' => $order->getId()]);
    }

    public function testCustomerRequestsReturnInsideWindow(): void
    {
        $user = $this->makeUser();
        $order = $this->makeOrder($user, Order::STATUS_DELIVERED, Payment::STATUS_PAID);
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/orders/'.$order->getId());
        $this->assertCount(1, $crawler->selectLink('Request a return'));

        $this->requestReturn($order);
        $this->assertResponseRedirects('/orders/returns');
        $this->assertSame(ReturnRequest::STATUS_REQUESTED, $this->returnFor($order)->getStatus());

        $crawler = $this->client->request('GET', '/orders/returns');
        $this->assertCount(1, $crawler->filter('.return'));
    }

    public function testReturnRejectedOutsideWindowOrBeforeDelivery(): void
    {
        $user = $this->makeUser();
        $late = $this->makeOrder($user, Order::STATUS_DELIVERED, Payment::STATUS_PAID);
        $late->setDeliveredAt(new \DateTimeImmutable('-8 days'));
        $pending = $this->makeOrder($user);
        $this->em->flush();
        $this->client->loginUser($user);

        $this->client->request('GET', '/orders/'.$late->getId().'/return');
        $this->assertResponseRedirects('/orders/'.$late->getId());
        $this->client->followRedirect();
        $this->assertSelectorTextContains('.flash-error', 'return window');

        $this->client->request('GET', '/orders/'.$pending->getId().'/return');
        $this->client->followRedirect();
        $this->assertSelectorTextContains('.flash-error', 'Only delivered');
    }

    public function testOnlyOneActiveReturnPerOrder(): void
    {
        $user = $this->makeUser();
        $order = $this->makeOrder($user, Order::STATUS_DELIVERED, Payment::STATUS_PAID);
        $this->client->loginUser($user);

        $this->requestReturn($order);
        $this->client->request('GET', '/orders/'.$order->getId().'/return');
        $this->assertResponseRedirects('/orders/'.$order->getId());
    }

    public function testCannotReturnAnotherCustomersOrder(): void
    {
        $order = $this->makeOrder($this->makeUser(), Order::STATUS_DELIVERED, Payment::STATUS_PAID);
        $this->client->loginUser($this->makeUser());
        $this->client->request('GET', '/orders/'.$order->getId().'/return');
        $this->assertResponseStatusCodeSame(404);
    }

    public function testReturnPhotoIsPrivate(): void
    {
        $user = $this->makeUser();
        $order = $this->makeOrder($user, Order::STATUS_DELIVERED, Payment::STATUS_PAID);
        $this->client->loginUser($user);

        $path = sys_get_temp_dir().'/return-'.uniqid().'.png';
        file_put_contents($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='));
        $this->requestReturn($order, $path);
        unlink($path);

        $return = $this->returnFor($order);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}\.png$/', $return->getPhoto());

        $this->client->request('GET', '/orders/returns/'.$return->getId().'/photo');
        $this->assertResponseIsSuccessful();

        $this->client->loginUser($this->makeUser());
        $this->client->request('GET', '/orders/returns/'.$return->getId().'/photo');
        $this->assertResponseStatusCodeSame(403);

        $this->client->loginUser($this->makeAdmin());
        $this->client->request('GET', '/orders/returns/'.$return->getId().'/photo');
        $this->assertResponseIsSuccessful();

        unlink(static::getContainer()->getParameter('kernel.project_dir').'/var/uploads/returns/'.$return->getPhoto());
    }

    public function testAdminApprovesThenRefundsRestoringStock(): void
    {
        $variant = $this->makeVariant(null, 1);
        $user = $this->makeUser();
        $order = $this->makeOrder($user, Order::STATUS_DELIVERED, Payment::STATUS_PAID, '1500.00', $variant, 2);
        $this->save((new ReturnRequest())->setReturnReason('Too big')->setParentOrder($order)->setCustomer($user));

        $this->client->loginUser($this->makeAdmin());
        $crawler = $this->client->request('GET', '/admin/returns');
        $this->client->submit($crawler->selectButton('Approve return')->form(['adminNote' => 'Pickup on Monday']));
        $crawler = $this->client->followRedirect();
        $this->assertSame(ReturnRequest::STATUS_APPROVED, $this->returnFor($order)->getStatus());

        $this->client->submit($crawler->selectButton('Refund return')->form());
        $return = $this->returnFor($order);
        $this->assertSame(ReturnRequest::STATUS_REFUNDED, $return->getStatus());
        $this->assertSame('Pickup on Monday', $return->getAdminNote());
        $this->assertSame(Payment::STATUS_REFUNDED, $return->getParentOrder()->getPayment()->getStatus());
        $this->assertSame(3, $this->em->getRepository(ProductVariant::class)->find($variant->getId())->getStock());

        $actions = array_map(static fn (AuditLog $log) => $log->getAction(), $this->em->getRepository(AuditLog::class)->findAll());
        $this->assertContains('return.approve', $actions);
        $this->assertContains('return.refund', $actions);
    }

    public function testAdminRejectsReturn(): void
    {
        $user = $this->makeUser();
        $order = $this->makeOrder($user, Order::STATUS_DELIVERED, Payment::STATUS_PAID);
        $this->save((new ReturnRequest())->setReturnReason('Changed mind')->setParentOrder($order)->setCustomer($user));

        $this->client->loginUser($this->makeAdmin());
        $crawler = $this->client->request('GET', '/admin/returns');
        $this->client->submit($crawler->selectButton('Reject return')->form(['adminNote' => 'Used item']));

        $return = $this->returnFor($order);
        $this->assertSame(ReturnRequest::STATUS_REJECTED, $return->getStatus());
        $this->assertSame(Payment::STATUS_PAID, $return->getParentOrder()->getPayment()->getStatus());
    }

    public function testRefundNeedsPaidPayment(): void
    {
        $variant = $this->makeVariant(null, 1);
        $user = $this->makeUser();
        $order = $this->makeOrder($user, Order::STATUS_DELIVERED, Payment::STATUS_PENDING, variant: $variant);
        $this->save((new ReturnRequest())->setReturnReason('Broken')->setStatus(ReturnRequest::STATUS_APPROVED)->setParentOrder($order)->setCustomer($user));

        $this->client->loginUser($this->makeAdmin());
        $crawler = $this->client->request('GET', '/admin/returns');
        $this->client->submit($crawler->selectButton('Refund return')->form());
        $this->client->followRedirect();
        $this->assertSelectorTextContains('.flash-error', 'no paid payment');
        $this->assertSame(ReturnRequest::STATUS_APPROVED, $this->returnFor($order)->getStatus());
        $this->assertSame(1, $this->em->getRepository(ProductVariant::class)->find($variant->getId())->getStock());
    }

    public function testCustomerCannotOpenAdminReturns(): void
    {
        $this->client->loginUser($this->makeUser());
        $this->client->request('GET', '/admin/returns');
        $this->assertResponseStatusCodeSame(403);
    }
}
