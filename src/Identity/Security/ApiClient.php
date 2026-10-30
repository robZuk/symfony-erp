<?php

namespace App\Identity\Security;

use App\Identity\Entity\ApiKey;
use App\Identity\Entity\ApiScope;
use App\Shared\Api\ApiConsumer;
use App\Shared\Audit\Actor;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * The system behind an API key, as the security layer sees it during one request.
 * Its roles are the key's scopes; there is no session and no password.
 */
final readonly class ApiClient implements UserInterface, Actor, ApiConsumer
{
    public const string ROLE = 'ROLE_API';

    private function __construct(
        private int $keyId,
        private string $name,
        /** @var list<string> */
        private array $roles,
    ) {
    }

    public static function of(ApiKey $key): self
    {
        return new self(
            (int) $key->getId(),
            $key->getName(),
            [self::ROLE, ...array_map(static fn (ApiScope $scope): string => $scope->role(), $key->getScopes())],
        );
    }

    public function getUserIdentifier(): string
    {
        return 'api-key-'.$this->keyId;
    }

    public function getRoles(): array
    {
        return $this->roles;
    }

    public function actorId(): ?int
    {
        return null;
    }

    public function actorName(): string
    {
        return 'API: '.$this->name;
    }

    public function apiConsumerId(): int
    {
        return $this->keyId;
    }

    public function apiConsumerName(): string
    {
        return $this->name;
    }
}
