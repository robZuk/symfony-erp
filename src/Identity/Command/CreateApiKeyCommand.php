<?php

namespace App\Identity\Command;

use App\Identity\Entity\ApiKey;
use App\Identity\Entity\ApiScope;
use App\Identity\Repository\ApiKeyRepository;
use App\Shared\Audit\ActivityLog;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:api-key:create', description: 'Creates a key for a system calling the REST API')]
final class CreateApiKeyCommand
{
    public function __construct(
        private readonly ApiKeyRepository $keys,
        private readonly ClockInterface $clock,
        private readonly ActivityLog $activity,
    ) {
    }

    /**
     * @param list<string> $scope
     */
    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'Who the key is for, e.g. "Sklep internetowy"')] string $name,
        #[Option(description: 'What the key may do, can be repeated: catalog:read, stock:read, orders:read, orders:write')] array $scope = [],
    ): int {
        $scopes = array_values(array_filter(array_map(ApiScope::tryFrom(...), $scope)));
        if ([] === $scope || \count($scopes) !== \count($scope) || '' === trim($name)) {
            $io->error(\sprintf('Give a name and at least one --scope out of: %s.', implode(', ', array_column(ApiScope::cases(), 'value'))));

            return Command::FAILURE;
        }

        [$key, $secret] = ApiKey::issue($name, $scopes, $this->clock->now());
        $this->keys->save($key);
        $this->activity->record('identity.api_key.created', \sprintf('Utworzono klucz API %s dla „%s”.', $key->getDisplayPrefix(), $key->getName()));

        $io->success(\sprintf('Key %s created for "%s" (%s).', $key->getDisplayPrefix(), $key->getName(), implode(', ', $scope)));
        $io->writeln('Hand it over now; it is not stored and cannot be shown again:');
        $io->newLine();
        $io->writeln($secret);
        $io->newLine();

        return Command::SUCCESS;
    }
}
