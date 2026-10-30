<?php

namespace App\Shared\Audit;

/**
 * An administrator working as another user. The log holds the administrator to account
 * and says whose account was used.
 */
final readonly class ImpersonatingActor implements Actor
{
    public function __construct(
        private Actor $administrator,
        private Actor $impersonated,
    ) {
    }

    public function actorId(): ?int
    {
        return $this->administrator->actorId();
    }

    public function actorName(): string
    {
        // The log column holds 120 characters
        return mb_substr(\sprintf('%s (jako %s)', $this->administrator->actorName(), $this->impersonated->actorName()), 0, 120);
    }
}
