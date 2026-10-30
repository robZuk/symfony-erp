<?php

namespace App\Identity\Command;

use App\Identity\Repository\ApiKeyRepository;
use App\Shared\Audit\ActivityLog;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * A revoked key is refused from the next request on. It is kept, not deleted, so the activity log
 * and the orders it placed still say which key it was.
 */
#[AsCommand(name: 'app:api-key:revoke', description: 'Revokes a REST API key')]
final class RevokeApiKeyCommand
{
    public function __construct(
        private readonly ApiKeyRepository $keys,
        private readonly ClockInterface $clock,
        private readonly ActivityLog $activity,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'The key as app:api-key:list shows it, e.g. erp_3f9c1a2b')] string $key,
    ): int {
        $apiKey = $this->keys->findOneBy(['displayPrefix' => $key]);
        if (null === $apiKey) {
            $io->error(\sprintf('There is no key %s.', $key));

            return Command::FAILURE;
        }

        $apiKey->revoke($this->clock->now());
        $this->keys->save($apiKey);
        $this->activity->record('identity.api_key.revoked', \sprintf('Unieważniono klucz API %s („%s”).', $apiKey->getDisplayPrefix(), $apiKey->getName()));
        $io->success(\sprintf('Key %s ("%s") is revoked.', $apiKey->getDisplayPrefix(), $apiKey->getName()));

        return Command::SUCCESS;
    }
}
