<?php

namespace App\EventSubscriber;

use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Http\Event\CheckPassportEvent;

/**
 * Blocks deactivated users at login and ends sessions that were already open.
 */
class DeactivatedUserSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private Security $security,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function onCheckPassport(CheckPassportEvent $event): void
    {
        $user = $event->getPassport()->getUser();

        if ($user instanceof User && !$user->isActive()) {
            throw new CustomUserMessageAccountStatusException('This account has been deactivated.');
        }
    }

    public function onRequestEvent(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $user = $this->security->getUser();

        if ($user instanceof User && !$user->isActive()) {
            $this->security->logout(false);
            $event->setResponse(new RedirectResponse($this->urlGenerator->generate('app_login')));
        }
    }

    public static function getSubscribedEvents(): array
    {
        return [
            CheckPassportEvent::class => ['onCheckPassport', -10],
            // Runs after the firewall (priority 8) so the session user is loaded.
            RequestEvent::class => ['onRequestEvent', 4],
        ];
    }
}
