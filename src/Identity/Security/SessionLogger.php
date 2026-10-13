<?php

namespace App\Identity\Security;

use App\Shared\Audit\ActivityLog;
use App\Shared\Audit\Actor;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;
use Symfony\Component\Security\Http\Event\LogoutEvent;

/**
 * Puts sign-ins, failed attempts and sign-outs into the activity log.
 */
final class SessionLogger
{
    public function __construct(private readonly ActivityLog $activity)
    {
    }

    #[AsEventListener]
    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        // Only a real sign-in through the form; not the session renewed after a password change
        if ('app_login' !== $event->getRequest()->attributes->get('_route')) {
            return;
        }

        $user = $event->getUser();
        if ($user instanceof Actor) {
            // The user is not "signed in" yet at this point, so the log is told who it is
            $this->activity->record('identity.session.signed_in', 'Zalogowano.', actor: $user);
        }
    }

    #[AsEventListener]
    public function onLoginFailure(LoginFailureEvent $event): void
    {
        $identifier = $event->getPassport()?->getBadge(UserBadge::class)?->getUserIdentifier()
            ?? (string) $event->getRequest()->request->get('_username', '');

        // Whatever was typed into the form is somebody else's text: cut to size, and escaped when shown
        $this->activity->record(
            'identity.session.sign_in_failed',
            \sprintf('Nieudana próba logowania na konto „%s”.', mb_substr($identifier, 0, 180)),
        );
    }

    #[AsEventListener]
    public function onLogout(LogoutEvent $event): void
    {
        $user = $event->getToken()?->getUser();
        if ($user instanceof Actor) {
            $this->activity->record('identity.session.signed_out', 'Wylogowano.', actor: $user);
        }
    }
}
