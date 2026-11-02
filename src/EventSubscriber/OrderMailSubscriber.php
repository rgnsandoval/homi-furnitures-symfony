<?php

namespace App\EventSubscriber;

use App\Entity\Order;
use App\Entity\Payment;
use App\Service\OrderTransitionService;
use App\Service\PaymentTransitionService;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\EventDispatcher\GenericEvent;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Runs only after the transaction commits, because the services dispatch after commit.
 * A mail failure is logged and never undoes the order.
 */
final class OrderMailSubscriber implements EventSubscriberInterface
{
    private const SHOP_ADDRESS = 'noreply@homi.test';
    private const ADMIN_ADDRESS = 'admin@homi.test';

    public function __construct(
        private MailerInterface $mailer,
        private LoggerInterface $logger,
        #[Autowire(env: 'default::APP_SEEDING')] private ?string $seeding = null,
    ) {
    }

    public function onOrderPlaced(GenericEvent $event): void
    {
        /** @var Order $order */
        $order = $event->getSubject();
        $number = $order->getOrderNumber();

        $this->send((string) $order->getCustomer()->getEmail(), "Order $number received", sprintf(
            "Thank you for your order %s.\nTotal: PHP %s\nEstimated delivery: %s",
            $number,
            $order->getTotal(),
            $order->getEstimatedDeliveryDate()?->format('F j, Y') ?? 'to be confirmed',
        ));
        $this->send(self::ADMIN_ADDRESS, "New order $number", "Order $number was placed for PHP {$order->getTotal()}.");
    }

    public function onOrderStatusChanged(GenericEvent $event): void
    {
        /** @var Order $order */
        $order = $event->getSubject();
        $this->send(
            (string) $order->getCustomer()->getEmail(),
            "Order {$order->getOrderNumber()} is now {$event['to']}",
            "Your order {$order->getOrderNumber()} moved from {$event['from']} to {$event['to']}.",
        );
    }

    public function onPaymentStatusChanged(GenericEvent $event): void
    {
        /** @var Payment $payment */
        $payment = $event->getSubject();
        $order = $payment->getParentOrder();
        $this->send(
            (string) $order->getCustomer()->getEmail(),
            "Payment for order {$order->getOrderNumber()} is now {$event['to']}",
            "The payment for order {$order->getOrderNumber()} moved from {$event['from']} to {$event['to']}.",
        );
    }

    public static function getSubscribedEvents(): array
    {
        return [
            OrderTransitionService::EVENT_PLACED => 'onOrderPlaced',
            OrderTransitionService::EVENT_STATUS_CHANGED => 'onOrderStatusChanged',
            PaymentTransitionService::EVENT_STATUS_CHANGED => 'onPaymentStatusChanged',
        ];
    }

    private function send(string $to, string $subject, string $body): void
    {
        if ('1' === $this->seeding) {
            return;
        }

        try {
            $this->mailer->send((new Email())
                ->from(new Address(self::SHOP_ADDRESS, 'Homi Furniture'))
                ->to($to)
                ->subject($subject)
                ->text($body));
        } catch (TransportExceptionInterface $e) {
            $this->logger->error('Order email failed: '.$e->getMessage(), ['subject' => $subject]);
        }
    }
}
