<?php

namespace App\EventSubscriber;

use App\Entity\Order;
use App\Entity\Payment;
use App\Service\OrderTransitionService;
use App\Service\PaymentTransitionService;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Events;

/**
 * Enforces at runtime that statuses only change through the transition services.
 */
#[AsDoctrineListener(event: Events::preUpdate)]
final class StatusGuardSubscriber
{
    public function preUpdate(PreUpdateEventArgs $args): void
    {
        $entity = $args->getObject();

        if ($entity instanceof Order && $args->hasChangedField('status') && !OrderTransitionService::isWriting()) {
            throw new \LogicException('Order.status may only change through OrderTransitionService.');
        }

        if ($entity instanceof Payment && $args->hasChangedField('status') && !PaymentTransitionService::isWriting()) {
            throw new \LogicException('Payment.status may only change through PaymentTransitionService.');
        }
    }
}
