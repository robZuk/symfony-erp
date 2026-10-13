<?php

namespace App\Shared\Audit;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One line of the activity log: who did what, and when. Entries are only ever added.
 */
#[ORM\Entity(repositoryClass: ActivityEntryRepository::class)]
#[ORM\Table(name: 'shared_activity_log')]
#[ORM\Index(name: 'idx_activity_occurred_at', columns: ['occurred_at'])]
class ActivityEntry
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private \DateTimeImmutable $occurredAt;

    /** Null when nobody was signed in: a console command or the demo data loader. */
    #[ORM\Column(nullable: true)]
    private ?int $actorId;

    /** The name at the time, kept even if the user is renamed later. */
    #[ORM\Column(length: 120)]
    private string $actorName;

    /** A stable code for filtering, e.g. "inventory.document.posted". */
    #[ORM\Column(length: 60)]
    private string $action;

    /** What happened, in words for the reader. */
    #[ORM\Column(type: Types::TEXT)]
    private string $summary;

    /** Route of the page that shows the thing the entry is about, if there is one. */
    #[ORM\Column(length: 80, nullable: true)]
    private ?string $route;

    /** @var array<string, int|string> */
    #[ORM\Column(type: Types::JSON)]
    private array $routeParameters;

    /**
     * @param array<string, int|string> $routeParameters
     */
    public function __construct(
        \DateTimeImmutable $occurredAt,
        ?int $actorId,
        string $actorName,
        string $action,
        string $summary,
        ?string $route = null,
        array $routeParameters = [],
    ) {
        $this->occurredAt = $occurredAt;
        $this->actorId = $actorId;
        $this->actorName = $actorName;
        $this->action = $action;
        $this->summary = $summary;
        $this->route = $route;
        $this->routeParameters = $routeParameters;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOccurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function getActorId(): ?int
    {
        return $this->actorId;
    }

    public function getActorName(): string
    {
        return $this->actorName;
    }

    public function getAction(): string
    {
        return $this->action;
    }

    public function getSummary(): string
    {
        return $this->summary;
    }

    public function getRoute(): ?string
    {
        return $this->route;
    }

    /**
     * @return array<string, int|string>
     */
    public function getRouteParameters(): array
    {
        return $this->routeParameters;
    }
}
