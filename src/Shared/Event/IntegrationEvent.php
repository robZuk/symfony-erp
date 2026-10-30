<?php

namespace App\Shared\Event;

/**
 * Something that happened in the business which other modules and other systems may react to.
 *
 * Events are dispatched inside the transaction that made the change and land in the "outbox"
 * transport, a table in the same database, so an event exists exactly when the change was committed.
 * The worker then publishes them to the RabbitMQ exchange "erp.events" (PublishToBroker).
 *
 * Events cross system boundaries as JSON, so they carry plain values (ids, numbers, amounts
 * in grosze), never entities, and a field once published is not renamed or removed.
 */
interface IntegrationEvent
{
    /**
     * Routing key on the topic exchange, "<module>.<subject>.<what happened>", e.g. "sales.order.shipped".
     * Subscribers bind their queues to a pattern such as "sales.order.*" or "invoicing.#".
     */
    public static function routingKey(): string;
}
