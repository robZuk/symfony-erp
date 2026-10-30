<?php

namespace App\Shared\Audit;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authentication\Token\SwitchUserToken;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Writes the activity log. Modules call record() right where something worth remembering happens.
 *
 * Inside a transaction the entry is saved together with the change it describes, so the log never
 * mentions something that was rolled back. Outside one it is saved straight away.
 */
final class ActivityLog implements ResetInterface
{
    public const string SYSTEM = 'System';

    private ?Actor $onBehalfOf = null;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly Security $security,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * Names the person to log under when nobody is signed in, e.g. in a background job
     * or while loading demo data. Pass null to go back to the signed-in user.
     */
    public function onBehalfOf(?Actor $actor): void
    {
        $this->onBehalfOf = $actor;
    }

    /**
     * Forgets onBehalfOf() between two requests or messages of a long-running process (kernel.reset),
     * so that one request can never be logged under the person named by another.
     */
    public function reset(): void
    {
        $this->onBehalfOf = null;
    }

    /**
     * The person to log under: the signed-in user, or the one named with onBehalfOf().
     *
     * While an administrator is working as somebody else, the administrator is the one held to account:
     * the entry carries their id and reads "Adam Administrator (jako Hanna Handlowiec)".
     */
    public function currentActor(): ?Actor
    {
        $token = $this->security->getToken();
        $user = $token?->getUser();
        if (!$user instanceof Actor) {
            return $this->onBehalfOf;
        }

        $impersonator = $token instanceof SwitchUserToken ? $token->getOriginalToken()->getUser() : null;

        return $impersonator instanceof Actor ? new ImpersonatingActor($impersonator, $user) : $user;
    }

    /**
     * @param string                    $action          a stable code such as "sales.order.confirmed"
     * @param string                    $summary         what happened, in words for the reader
     * @param string|null               $route           route of the page showing the subject, for a link in the log
     * @param array<string, int|string> $routeParameters
     * @param Actor|null                $actor           who did it, when that is not the signed-in user (e.g. at sign-in itself)
     */
    public function record(string $action, string $summary, ?string $route = null, array $routeParameters = [], ?Actor $actor = null): void
    {
        $actor ??= $this->currentActor();

        $this->entityManager->persist(new ActivityEntry(
            $this->clock->now(),
            $actor?->actorId(),
            $actor?->actorName() ?? self::SYSTEM,
            $action,
            $summary,
            $route,
            $routeParameters,
        ));

        if (!$this->entityManager->getConnection()->isTransactionActive()) {
            $this->entityManager->flush();
        }
    }
}
