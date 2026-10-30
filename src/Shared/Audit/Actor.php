<?php

namespace App\Shared\Audit;

/**
 * Whoever did something, as the activity log sees them: the signed-in person (the user class of the
 * Identity module) or a system calling the REST API with its key.
 */
interface Actor
{
    /** The user's id; null for a system, which is not a user. */
    public function actorId(): ?int;

    public function actorName(): string;
}
