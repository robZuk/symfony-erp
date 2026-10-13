<?php

namespace App\Shared\Audit;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Writes the activity log. Modules call record() right where something worth remembering happens.
 *
 * Inside a transaction the entry is saved together with the change it describes, so the log never
 * mentions something that was rolled back. Outside one it is saved straight away.
 */
final class ActivityLog
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
     * @param string                    $action          a stable code such as "sales.order.confirmed"
     * @param string                    $summary         what happened, in words for the reader
     * @param string|null               $route           route of the page showing the subject, for a link in the log
     * @param array<string, int|string> $routeParameters
     */
    public function record(string $action, string $summary, ?string $route = null, array $routeParameters = []): void
    {
        $user = $this->security->getUser();
        $actor = $user instanceof Actor ? $user : $this->onBehalfOf;

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
