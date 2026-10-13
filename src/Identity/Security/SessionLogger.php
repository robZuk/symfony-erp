<?php

namespace App\Identity\Security;

use App\Shared\Audit\ActivityEntryRepository;
use App\Shared\Audit\ActivityLog;
use App\Shared\Audit\Actor;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Core\Authentication\Token\SwitchUserToken;
use Symfony\Component\Security\Core\Exception\TooManyLoginAttemptsAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;
use Symfony\Component\Security\Http\Event\LogoutEvent;
use Symfony\Component\Security\Http\Event\SwitchUserEvent;

/**
 * Puts sign-ins, failed attempts, lock-outs and sign-outs into the activity log.
 */
final class SessionLogger
{
    public function __construct(
        private readonly ActivityLog $activity,
        private readonly ActivityEntryRepository $entries,
        private readonly ClockInterface $clock,
        private readonly Security $security,
    ) {
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
        $identifier = mb_substr($identifier, 0, 180);

        if (!$event->getException() instanceof TooManyLoginAttemptsAuthenticationException) {
            $this->activity->record('identity.session.sign_in_failed', \sprintf('Nieudana próba logowania na konto „%s”.', $identifier));

            return;
        }

        // The limit has been reached (see login_throttling in security.yaml). Every further attempt lands here,
        // so it is reported once per lock-out; otherwise the attacker would be the one filling the log.
        $summary = \sprintf('Zablokowano logowanie na konto „%s” po zbyt wielu nieudanych próbach.', $identifier);
        if (!$this->entries->hasSince('identity.session.sign_in_locked', $summary, $this->clock->now()->modify('-15 minutes'))) {
            $this->activity->record('identity.session.sign_in_locked', $summary);
        }
    }

    /**
     * An administrator starting or ending work as another user. Fired by the firewall's switch_user feature.
     */
    #[AsEventListener]
    public function onSwitchUser(SwitchUserEvent $event): void
    {
        $token = $event->getToken();
        $target = $event->getTargetUser();

        if ($token instanceof SwitchUserToken) {
            $administrator = $token->getOriginalToken()->getUser();
            if ($administrator instanceof Actor && $target instanceof Actor) {
                $this->activity->record(
                    'identity.session.impersonation_started',
                    \sprintf('Zalogowano jako %s.', $target->actorName()),
                    actor: $administrator,
                );
            }

            return;
        }

        // Coming back: the target is the administrator again, and the user they were working as is on the way out
        $impersonated = $this->security->getUser();
        if ($target instanceof Actor && $impersonated instanceof Actor) {
            $this->activity->record(
                'identity.session.impersonation_ended',
                \sprintf('Wrócono z konta %s.', $impersonated->actorName()),
                actor: $target,
            );
        }
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
