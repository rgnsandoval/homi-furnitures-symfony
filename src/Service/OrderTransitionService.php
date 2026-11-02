<?php

namespace App\Service;

use App\Entity\Order;
use App\Entity\OrderStatusHistory;
use App\Entity\Payment;
use App\Entity\StockMovement;
use App\Entity\User;
use App\Repository\DiscountCodeRepository;
use App\Repository\ProductVariantRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\GenericEvent;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * The only writer of Order.status. StatusGuardSubscriber rejects any other change.
 */
class OrderTransitionService
{
    public const EVENT_PLACED = 'app.order.placed';
    public const EVENT_STATUS_CHANGED = 'app.order.status_changed';

    private const TRANSITIONS = [
        Order::STATUS_PENDING => [Order::STATUS_CONFIRMED, Order::STATUS_CANCELLED],
        Order::STATUS_CONFIRMED => [Order::STATUS_PACKED, Order::STATUS_CANCELLED],
        Order::STATUS_PACKED => [Order::STATUS_SHIPPED],
        Order::STATUS_SHIPPED => [Order::STATUS_DELIVERED],
        Order::STATUS_DELIVERED => [],
        Order::STATUS_CANCELLED => [],
    ];

    private static bool $writing = false;

    public function __construct(
        private EntityManagerInterface $em,
        private EventDispatcherInterface $dispatcher,
        private PaymentTransitionService $payments,
        private ProductVariantRepository $variants,
        private DiscountCodeRepository $discountCodes,
    ) {
    }

    public static function isWriting(): bool
    {
        return self::$writing;
    }

    /**
     * @return list<string>
     */
    public function allowedFrom(string $status): array
    {
        return self::TRANSITIONS[$status] ?? [];
    }

    /**
     * @throws \DomainException when the move is not allowed; nothing is changed
     */
    public function transition(Order $order, string $to, ?User $by = null, ?string $note = null): void
    {
        $paymentFailed = false;

        $this->em->beginTransaction();
        try {
            $this->em->lock($order, LockMode::PESSIMISTIC_WRITE);
            $this->em->refresh($order);

            $from = (string) $order->getStatus();
            if (!in_array($to, $this->allowedFrom($from), true)) {
                throw new \DomainException(sprintf('A %s order cannot become %s.', $from, $to));
            }

            $payment = $order->getPayment();
            if (Order::STATUS_PACKED === $to && Payment::METHOD_BANK === $payment?->getMethod() && Payment::STATUS_PAID !== $payment->getStatus()) {
                throw new \DomainException('A bank transfer order cannot be packed until its payment is paid.');
            }

            $order->setStatus($to);
            if (Order::STATUS_DELIVERED === $to) {
                $order->setDeliveredAt(new \DateTimeImmutable());
            }

            if (Order::STATUS_CANCELLED === $to) {
                $this->restoreStock($order, $by);
                if (null !== $order->getDiscountCode()) {
                    $this->discountCodes->releaseUse($order->getDiscountCode());
                }
                if (Payment::STATUS_PENDING === $payment?->getStatus()) {
                    $this->payments->apply($payment, Payment::STATUS_FAILED, $by, 'Order cancelled');
                    $paymentFailed = true;
                }
            }

            $this->em->persist((new OrderStatusHistory())
                ->setParentOrder($order)
                ->setFromStatus($from)
                ->setToStatus($to)
                ->setChangedBy($by)
                ->setNote($note));

            $this->flush();
            $this->em->commit();
        } catch (\Throwable $e) {
            $this->em->rollback();
            throw $e;
        }

        $this->dispatcher->dispatch(new GenericEvent($order, ['from' => $from, 'to' => $to]), self::EVENT_STATUS_CHANGED);
        if ($paymentFailed) {
            $this->payments->dispatchChanged($payment, Payment::STATUS_PENDING, Payment::STATUS_FAILED);
        }
    }

    public function cancel(Order $order, string $reason, ?User $by = null): void
    {
        $order->setCancelReason($reason);
        try {
            $this->transition($order, Order::STATUS_CANCELLED, $by, $reason);
        } catch (\DomainException $e) {
            $this->em->refresh($order);
            throw $e;
        }
        // transition() refreshed the order under its lock, so the reason is written afterwards.
        $order->setCancelReason($reason);
        $this->em->flush();
    }

    public function recordCreated(Order $order, ?User $by = null): void
    {
        $this->em->persist((new OrderStatusHistory())
            ->setParentOrder($order)
            ->setToStatus((string) $order->getStatus())
            ->setChangedBy($by));
    }

    public function dispatchPlaced(Order $order): void
    {
        $this->dispatcher->dispatch(new GenericEvent($order), self::EVENT_PLACED);
    }

    private function flush(): void
    {
        self::$writing = true;
        try {
            $this->payments->flush();
        } finally {
            self::$writing = false;
        }
    }

    private function restoreStock(Order $order, ?User $by): void
    {
        $ids = [];
        foreach ($order->getItems() as $item) {
            $ids[] = (int) $item->getVariant()->getId();
        }
        $locked = $this->variants->lockForUpdate($ids);
        $admin = null !== $by && in_array('ROLE_ADMIN', $by->getRoles(), true) ? $by : null;

        foreach ($order->getItems() as $item) {
            $variant = $locked[$item->getVariant()->getId()];
            $variant->setStock($variant->getStock() + (int) $item->getQuantity());

            $this->em->persist((new StockMovement())
                ->setVariant($variant)
                ->setQuantityChange((int) $item->getQuantity())
                ->setMovementReason(StockMovement::REASON_ORDER)
                ->setNote('Order '.$order->getOrderNumber().' cancelled')
                ->setAdminUser($admin));
        }
    }
}
