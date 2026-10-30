<?php

namespace App\Shared\Api;

/**
 * The system calling the REST API, as other modules see it. Implemented by the API client of the
 * Identity module, which authenticates the key.
 */
interface ApiConsumer
{
    /** Identifies the API key, e.g. to keep the orders of one client apart from another's. */
    public function apiConsumerId(): int;

    public function apiConsumerName(): string;
}
