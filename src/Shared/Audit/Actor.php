<?php

namespace App\Shared\Audit;

/**
 * The signed-in person as the activity log sees them. Implemented by the user class of the Identity module.
 */
interface Actor
{
    public function actorId(): int;

    public function actorName(): string;
}
