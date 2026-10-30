<?php

namespace App\Shared\Audit;

use App\Shared\Twig\MoneyExtension;
use App\Shared\Twig\QuantityExtension;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;
use Doctrine\ORM\Mapping\ClassMetadata;
use Psr\Clock\ClockInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Logs every creation, change and removal of an Audited entity, whoever makes it and wherever in the code.
 * Nothing has to remember to call the log, so no edit can slip past it.
 *
 * The changes are read while Doctrine prepares the flush and written right after it, on the same
 * connection: inside a transaction they are committed or rolled back together with the data.
 */
#[AsDoctrineListener(event: Events::onFlush)]
#[AsDoctrineListener(event: Events::postFlush)]
final class AuditListener implements ResetInterface
{
    /** @var list<array{action: string, verb: string, entity: Audited, details: string}> */
    private array $pending = [];

    public function __construct(
        private readonly ActivityLog $activity,
        private readonly ClockInterface $clock,
        private readonly MoneyExtension $money,
        private readonly QuantityExtension $quantity,
    ) {
    }

    public function onFlush(OnFlushEventArgs $event): void
    {
        // Each flush reports its own changes only. A flush that failed after this point never reached
        // postFlush; without this its changes would be logged by the next, unrelated flush of the
        // same long-running process (a Messenger worker, the FrankenPHP worker)
        $this->pending = [];
        $unitOfWork = $event->getObjectManager()->getUnitOfWork();

        foreach ($unitOfWork->getScheduledEntityInsertions() as $entity) {
            if ($entity instanceof Audited) {
                $this->pending[] = ['action' => 'created', 'verb' => 'Dodano', 'entity' => $entity, 'details' => ''];
            }
        }

        foreach ($unitOfWork->getScheduledEntityUpdates() as $entity) {
            if (!$entity instanceof Audited) {
                continue;
            }

            $changes = $this->describeChanges(
                $entity,
                $unitOfWork->getEntityChangeSet($entity),
                $event->getObjectManager()->getClassMetadata($entity::class),
            );
            // A save that touched nothing worth reporting leaves no entry
            if ([] !== $changes) {
                $this->pending[] = ['action' => 'updated', 'verb' => 'Zmieniono', 'entity' => $entity, 'details' => implode('; ', $changes)];
            }
        }

        foreach ($unitOfWork->getScheduledEntityDeletions() as $entity) {
            if ($entity instanceof Audited) {
                $this->pending[] = ['action' => 'deleted', 'verb' => 'Usunięto', 'entity' => $entity, 'details' => ''];
            }
        }
    }

    /**
     * Between two requests or two messages of a long-running process (kernel.reset).
     */
    public function reset(): void
    {
        $this->pending = [];
    }

    public function postFlush(PostFlushEventArgs $event): void
    {
        $pending = $this->pending;
        $this->pending = [];
        if ([] === $pending) {
            return;
        }

        $actor = $this->activity->currentActor();
        $connection = $event->getObjectManager()->getConnection();

        foreach ($pending as $change) {
            $entity = $change['entity'];
            // Asked for only now: a new record has its id, and so its address, after the flush
            $route = 'deleted' === $change['action'] ? null : $entity->auditRoute();

            // Written with plain SQL: starting another flush from inside a flush is asking for trouble
            $connection->insert('shared_activity_log', [
                'occurred_at' => $this->clock->now()->format('Y-m-d H:i:s'),
                'actor_id' => $actor?->actorId(),
                'actor_name' => $actor?->actorName() ?? ActivityLog::SYSTEM,
                'action' => $entity::auditCode().'.'.$change['action'],
                'summary' => \sprintf('%s %s%s', $change['verb'], $entity->auditLabel(), '' === $change['details'] ? '.' : ': '.$change['details'].'.'),
                'route' => $route[0] ?? null,
                'route_parameters' => json_encode($route[1] ?? [], \JSON_THROW_ON_ERROR),
            ]);
        }
    }

    /**
     * @param array<string, mixed>  $changeSet property => [old value, new value]
     * @param ClassMetadata<object> $metadata
     *
     * @return list<string> one "label: old → new" per reported field
     */
    private function describeChanges(Audited $entity, array $changeSet, ClassMetadata $metadata): array
    {
        $changes = [];
        foreach ($entity::auditFields() as $property => $definition) {
            // Collections of related records are not fields with an old and a new value
            if (!isset($changeSet[$property]) || !\is_array($changeSet[$property])) {
                continue;
            }

            [$label, $format] = \is_array($definition) ? $definition : [$definition, null];
            [$old, $new] = $changeSet[$property];

            // Doctrine reports enum columns by their stored value; turn it back into the enum to print its label
            $enum = $metadata->hasField($property) ? $metadata->getFieldMapping($property)->enumType : null;
            if (null !== $enum && is_subclass_of($enum, \BackedEnum::class)) {
                $old = \is_int($old) || \is_string($old) ? $enum::tryFrom($old) ?? $old : $old;
                $new = \is_int($new) || \is_string($new) ? $enum::tryFrom($new) ?? $new : $new;
            }
            $changes[] = \sprintf('%s: %s → %s', $label, $this->format($old, $format), $this->format($new, $format));
        }

        return $changes;
    }

    private function format(mixed $value, ?string $format): string
    {
        return match (true) {
            null === $value, '' === $value => '(puste)',
            \is_bool($value) => $value ? 'tak' : 'nie',
            'money' === $format && \is_int($value) => $this->money->format($value),
            'quantity' === $format && \is_int($value) => $this->quantity->format($value),
            $value instanceof \BackedEnum => method_exists($value, 'label') ? (string) $value->label() : (string) $value->value,
            $value instanceof \DateTimeInterface => $value->format('d.m.Y'),
            \is_scalar($value) => (string) $value,
            default => '(zmieniono)',
        };
    }
}
