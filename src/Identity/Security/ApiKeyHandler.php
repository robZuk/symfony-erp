<?php

namespace App\Identity\Security;

use App\Identity\Repository\ApiKeyRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Http\AccessToken\AccessTokenHandlerInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;

/**
 * Authenticates REST API requests (firewall "api", `access_token` in security.yaml): the token from
 * "Authorization: Bearer <key>" must be a key that exists and was not revoked.
 */
final class ApiKeyHandler implements AccessTokenHandlerInterface
{
    public function __construct(
        private readonly ApiKeyRepository $keys,
        private readonly ClockInterface $clock,
    ) {
    }

    public function getUserBadgeFrom(#[\SensitiveParameter] string $accessToken): UserBadge
    {
        $key = $this->keys->findUsable($accessToken)
            // The same answer for a wrong key and a revoked one: nothing to learn by guessing
            ?? throw new BadCredentialsException('Invalid API key.');

        if ($key->markUsed($this->clock->now())) {
            $this->keys->save($key);
        }

        $client = ApiClient::of($key);

        return new UserBadge($client->getUserIdentifier(), static fn (): ApiClient => $client);
    }
}
