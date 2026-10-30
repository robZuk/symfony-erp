<?php

namespace App\Shared\Event;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Bridge\Amqp\Transport\AmqpStamp;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;

/**
 * Relay of the transactional outbox: takes an event the worker read from the "outbox" table
 * and publishes it to RabbitMQ under its routing key.
 *
 * If publishing fails, the handler fails and the outbox row is retried, so an event is never lost.
 * It may, rarely, be published twice (published, then the worker died before acknowledging the row),
 * so subscribers must handle an event idempotently.
 *
 * Only messages coming from the outbox are relayed; the same event received from RabbitMQ
 * goes to the subscribers' handlers and never back to the broker.
 */
#[AsMessageHandler(fromTransport: 'outbox')]
final class PublishToBroker
{
    public function __construct(private readonly MessageBusInterface $bus)
    {
    }

    public function __invoke(IntegrationEvent $event): void
    {
        $this->bus->dispatch($event, [
            new TransportNamesStamp(['events']),
            new AmqpStamp($event::routingKey()),
        ]);
    }
}
