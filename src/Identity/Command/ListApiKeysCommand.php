<?php

namespace App\Identity\Command;

use App\Identity\Entity\ApiKey;
use App\Identity\Entity\ApiScope;
use App\Identity\Repository\ApiKeyRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:api-key:list', description: 'Lists the REST API keys, without their secrets')]
final class ListApiKeysCommand
{
    public function __construct(private readonly ApiKeyRepository $keys)
    {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $io->table(
            ['Key', 'For', 'Scopes', 'Created', 'Last used', 'Status'],
            array_map(static fn (ApiKey $key): array => [
                $key->getDisplayPrefix(),
                $key->getName(),
                implode(', ', array_map(static fn (ApiScope $scope): string => $scope->value, $key->getScopes())),
                $key->getCreatedAt()->format('Y-m-d H:i'),
                $key->getLastUsedAt()?->format('Y-m-d H:i') ?? '—',
                $key->isRevoked() ? 'revoked' : 'active',
            ], $this->keys->newestFirst()),
        );

        return Command::SUCCESS;
    }
}
