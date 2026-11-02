<?php

namespace App\Tests;

use App\Entity\DiscountCode;
use App\Entity\Order;
use App\Entity\OrderStatusHistory;
use App\Entity\Payment;
use App\Entity\PaymentStatusHistory;
use App\Entity\ProductVariant;
use App\EventSubscriber\OrderMailSubscriber;
use App\Service\OrderTransitionService;
use App\Service\PaymentTransitionService;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\GenericEvent;
use Symfony\Component\Mailer\MailerInterface;

class TransitionTest extends BaseWebTest
{
    private function orders(): OrderTransitionService
    {
        return static::getContainer()->get(OrderTransitionService::class);
    }

    private function payments(): PaymentTransitionService
    {
        return static::getContainer()->get(PaymentTransitionService::class);
    }

    public function testFullHappyPathWritesHistory(): void
    {
        $order = $this->makeOrder($this->makeUser());
        foreach ([Order::STATUS_CONFIRMED, Order::STATUS_PACKED, Order::STATUS_SHIPPED, Order::STATUS_DELIVERED] as $status) {
            $this->orders()->transition($order, $status);
        }

        $this->assertSame(Order::STATUS_DELIVERED, $order->getStatus());
        $this->assertNotNull($order->getDeliveredAt());
        $this->assertCount(4, $this->em->getRepository(OrderStatusHistory::class)->findBy(['parentOrder' => $order]));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidOrderMoves')]
    public function testInvalidOrderTransitionsAreRejected(string $from, string $to): void
    {
        $order = $this->makeOrder($this->makeUser(), $from);

        $this->expectException(\DomainException::class);
        $this->orders()->transition($order, $to);
    }

    public static function invalidOrderMoves(): array
    {
        return [
            ['pending', 'shipped'],
            ['pending', 'delivered'],
            ['packed', 'cancelled'],
            ['shipped', 'cancelled'],
            ['delivered', 'cancelled'],
            ['cancelled', 'pending'],
            ['delivered', 'pending'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidPaymentMoves')]
    public function testInvalidPaymentTransitionsAreRejected(string $from, string $to): void
    {
        $order = $this->makeOrder($this->makeUser(), paymentStatus: $from);

        $this->expectException(\DomainException::class);
        $this->payments()->transition($order->getPayment(), $to);
    }

    public static function invalidPaymentMoves(): array
    {
        return [
            ['pending', 'refunded'],
            ['paid', 'pending'],
            ['paid', 'failed'],
            ['failed', 'paid'],
            ['refunded', 'paid'],
        ];
    }

    public function testRuntimeGuardRejectsDirectOrderStatusChange(): void
    {
        $order = $this->makeOrder($this->makeUser());
        $order->setStatus(Order::STATUS_DELIVERED);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('OrderTransitionService');
        $this->em->flush();
    }

    public function testRuntimeGuardRejectsDirectPaymentStatusChange(): void
    {
        $order = $this->makeOrder($this->makeUser());
        $order->getPayment()->setStatus(Payment::STATUS_PAID);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('PaymentTransitionService');
        $this->em->flush();
    }

    public function testCancelRestoresStockAndFailsPendingPayment(): void
    {
        $variant = $this->makeVariant(null, 3);
        $order = $this->makeOrder($this->makeUser(), variant: $variant, quantity: 2);

        $this->orders()->cancel($order, 'Changed my mind');

        $this->assertSame(Order::STATUS_CANCELLED, $order->getStatus());
        $this->assertSame('Changed my mind', $order->getCancelReason());
        $this->assertSame(Payment::STATUS_FAILED, $order->getPayment()->getStatus());
        $this->assertSame(5, $this->em->getRepository(ProductVariant::class)->find($variant->getId())->getStock());
        $this->assertCount(1, $this->em->getRepository(PaymentStatusHistory::class)->findBy(['payment' => $order->getPayment()]));
    }

    public function testCancelKeepsPaidPaymentForAdminRefund(): void
    {
        $order = $this->makeOrder($this->makeUser(), paymentStatus: Payment::STATUS_PAID);
        $this->orders()->cancel($order, 'Out of stock');
        $this->assertSame(Payment::STATUS_PAID, $order->getPayment()->getStatus());
    }

    public function testCancelReleasesVoucherUse(): void
    {
        $code = (new DiscountCode())->setCode('BACK')->setDiscountType(DiscountCode::TYPE_FIXED)->setDiscountValue('10.00')->setTimesUsed(1);
        $this->save($code);
        $order = $this->makeOrder($this->makeUser());
        $order->setDiscountCode($code);
        $this->em->flush();

        $this->orders()->cancel($order, 'Reason');
        $this->assertSame(0, $code->getTimesUsed());
    }

    public function testBankOrderCannotBePackedUntilPaid(): void
    {
        $order = $this->makeOrder($this->makeUser(), Order::STATUS_CONFIRMED, method: Payment::METHOD_BANK);

        try {
            $this->orders()->transition($order, Order::STATUS_PACKED);
            $this->fail('Packing an unpaid bank order must be rejected.');
        } catch (\DomainException) {
            $this->assertSame(Order::STATUS_CONFIRMED, $order->getStatus());
        }

        $this->payments()->transition($order->getPayment(), Payment::STATUS_PAID);
        $this->orders()->transition($order, Order::STATUS_PACKED);
        $this->assertSame(Order::STATUS_PACKED, $order->getStatus());
    }

    public function testPaymentPathsAndRetry(): void
    {
        $payment = $this->makeOrder($this->makeUser(), method: Payment::METHOD_BANK)->getPayment();

        $this->payments()->transition($payment, Payment::STATUS_FAILED);
        $this->payments()->transition($payment, Payment::STATUS_PENDING);
        $this->payments()->transition($payment, Payment::STATUS_PAID);
        $this->assertNotNull($payment->getPaidAt());
        $this->payments()->transition($payment, Payment::STATUS_REFUNDED);

        $this->assertCount(4, $this->em->getRepository(PaymentStatusHistory::class)->findBy(['payment' => $payment]));
    }

    public function testCustomerCancelOnlyWhilePending(): void
    {
        $user = $this->makeUser();
        $this->client->loginUser($user);
        $variant = $this->makeVariant(null, 1);
        $order = $this->makeOrder($user, variant: $variant);

        $crawler = $this->client->request('GET', '/orders/'.$order->getId());
        $this->client->submit($crawler->selectButton('Cancel order')->form(['reason' => 'Oops']));
        $this->assertResponseRedirects();
        $this->em->clear();
        $this->assertSame(Order::STATUS_CANCELLED, $this->em->getRepository(Order::class)->find($order->getId())->getStatus());
        $this->assertSame(2, $this->em->getRepository(ProductVariant::class)->find($variant->getId())->getStock());

        $confirmed = $this->makeOrder($this->em->getRepository($user::class)->find($user->getId()), Order::STATUS_CONFIRMED);
        $crawler = $this->client->request('GET', '/orders/'.$confirmed->getId());
        $this->assertCount(0, $crawler->selectButton('Cancel order'));
    }

    public function testEmailsAreSkippedWhenSeeding(): void
    {
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects($this->never())->method('send');
        $subscriber = new OrderMailSubscriber($mailer, new NullLogger(), '1');

        $order = $this->makeOrder($this->makeUser());
        $subscriber->onOrderPlaced(new GenericEvent($order));
        $subscriber->onOrderStatusChanged(new GenericEvent($order, ['from' => 'pending', 'to' => 'confirmed']));
    }

    public function testEmailsAreSentWhenNotSeeding(): void
    {
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects($this->exactly(2))->method('send');
        $subscriber = new OrderMailSubscriber($mailer, new NullLogger(), null);

        $subscriber->onOrderPlaced(new GenericEvent($this->makeOrder($this->makeUser())));
    }

    private function proofFile(string $contents = 'png'): string
    {
        $path = sys_get_temp_dir().'/proof-'.uniqid().'.png';
        file_put_contents($path, 'png' === $contents
            ? base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==')
            : $contents);

        return $path;
    }

    public function testProofUploadIsPrivateToOwnerAndAdmin(): void
    {
        $user = $this->makeUser();
        $order = $this->makeOrder($user, method: Payment::METHOD_BANK);
        $paymentId = $order->getPayment()->getId();
        $this->client->loginUser($user);

        $path = $this->proofFile();
        $crawler = $this->client->request('GET', '/orders/'.$order->getId());
        $form = $crawler->selectButton('Upload proof')->form();
        $form['payment_proof_form[proof]']->upload($path);
        $this->client->submit($form);
        unlink($path);
        $this->assertResponseRedirects();

        $this->em->clear();
        $stored = $this->em->getRepository(Payment::class)->find($paymentId)->getProofFile();
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}\.png$/', $stored);
        $projectDir = static::getContainer()->getParameter('kernel.project_dir');
        $this->assertFileExists($projectDir.'/var/uploads/proofs/'.$stored);
        $this->assertFileDoesNotExist($projectDir.'/public/uploads/proofs/'.$stored);

        $this->client->request('GET', '/orders/proof/'.$paymentId);
        $this->assertResponseIsSuccessful();

        $this->client->loginUser($this->makeUser());
        $this->client->request('GET', '/orders/proof/'.$paymentId);
        $this->assertResponseStatusCodeSame(403);

        $this->client->loginUser($this->makeAdmin());
        $this->client->request('GET', '/orders/proof/'.$paymentId);
        $this->assertResponseIsSuccessful();

        $this->client->getCookieJar()->clear();
        $this->client->request('GET', '/orders/proof/'.$paymentId);
        $this->assertResponseRedirects('/login');

        unlink($projectDir.'/var/uploads/proofs/'.$stored);
    }

    public function testProofRejectsBadFileAndNewProofRetriesFailedPayment(): void
    {
        $user = $this->makeUser();
        $order = $this->makeOrder($user, paymentStatus: Payment::STATUS_FAILED, method: Payment::METHOD_BANK);
        $this->client->loginUser($user);

        $bad = $this->proofFile('<?php echo 1;');
        $crawler = $this->client->request('GET', '/orders/'.$order->getId());
        $form = $crawler->selectButton('Upload proof')->form();
        $form['payment_proof_form[proof]']->upload($bad);
        $this->client->submit($form);
        unlink($bad);
        $this->assertResponseStatusCodeSame(422);

        $good = $this->proofFile();
        $crawler = $this->client->request('GET', '/orders/'.$order->getId());
        $form = $crawler->selectButton('Upload proof')->form();
        $form['payment_proof_form[proof]']->upload($good);
        $this->client->submit($form);
        unlink($good);

        $this->em->clear();
        $payment = $this->em->getRepository(Payment::class)->find($order->getPayment()->getId());
        $this->assertSame(Payment::STATUS_PENDING, $payment->getStatus());
        unlink(static::getContainer()->getParameter('kernel.project_dir').'/var/uploads/proofs/'.$payment->getProofFile());
    }
}
