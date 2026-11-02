<?php

namespace App\Service;

use App\Entity\Payment;
use App\Entity\PaymentStatusHistory;
use App\Entity\User;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\GenericEvent;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * The only writer of Payment.status. StatusGuardSubscriber rejects any other change.
 */
class PaymentTransitionService
{
    public const EVENT_STATUS_CHANGED = 'app.payment.status_changed';

    private const TRANSITIONS = [
        Payment::STATUS_PENDING => [Payment::STATUS_PAID, Payment::STATUS_FAILED],
        Payment::STATUS_PAID => [Payment::STATUS_REFUNDED],
        Payment::STATUS_FAILED => [Payment::STATUS_PENDING],
        Payment::STATUS_REFUNDED => [],
    ];

    private static bool $writing = false;

    public function __construct(
        private EntityManagerInterface $em,
        private EventDispatcherInterface $dispatcher,
    ) {
    }

    public static function isWriting(): bool
    {
        return self::$writing;
    }

    /**
     * @throws \DomainException when the move is not allowed; nothing is changed
     */
    public function transition(Payment $payment, string $to, ?User $by = null, ?string $note = null): void
    {
        $this->em->beginTransaction();
        try {
            $this->em->lock($payment, LockMode::PESSIMISTIC_WRITE);
            $this->em->refresh($payment);
            $from = (string) $payment->getStatus();
            $this->apply($payment, $to, $by, $note);
            $this->flush();
            $this->em->commit();
        } catch (\Throwable $e) {
            $this->em->rollback();
            throw $e;
        }

        $this->dispatchChanged($payment, $from, $to);
    }

    /**
     * Joins the caller's transaction: the caller flushes through flush() and dispatches after commit.
     */
    public function apply(Payment $payment, string $to, ?User $by = null, ?string $note = null): void
    {
        $from = (string) $payment->getStatus();
        if (!in_array($to, self::TRANSITIONS[$from] ?? [], true)) {
            throw new \DomainException(sprintf('A %s payment cannot become %s.', $from, $to));
        }

        $payment->setStatus($to);
        if (Payment::STATUS_PAID === $to) {
            $payment->setPaidAt(new \DateTimeImmutable());
        }

        $this->em->persist((new PaymentStatusHistory())
            ->setPayment($payment)
            ->setFromStatus($from)
            ->setToStatus($to)
            ->setChangedBy($by)
            ->setNote($note));
    }

    public function recordCreated(Payment $payment, ?User $by = null): void
    {
        $this->em->persist((new PaymentStatusHistory())
            ->setPayment($payment)
            ->setToStatus((string) $payment->getStatus())
            ->setChangedBy($by));
    }

    public function flush(): void
    {
        self::$writing = true;
        try {
            $this->em->flush();
        } finally {
            self::$writing = false;
        }
    }

    public function dispatchChanged(Payment $payment, string $from, string $to): void
    {
        $this->dispatcher->dispatch(new GenericEvent($payment, ['from' => $from, 'to' => $to]), self::EVENT_STATUS_CHANGED);
    }
}
