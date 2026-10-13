<?php

namespace App\Identity\Command;

use App\Identity\Entity\User;
use App\Identity\Repository\UserRepository;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[AsCommand(name: 'app:user:create', description: 'Creates a user account')]
final class CreateUserCommand
{
    private const int MIN_PASSWORD_LENGTH = 8;

    public function __construct(
        private readonly UserRepository $users,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly ValidatorInterface $validator,
    ) {
    }

    /**
     * @param list<string> $role
     */
    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'E-mail used to sign in')] string $email,
        #[Argument(description: 'Full name shown in the application')] string $fullName,
        #[Option(description: 'Role to grant, can be repeated (e.g. ROLE_ADMIN)')] array $role = [],
        #[Option(description: 'Password; asked interactively when omitted')] ?string $password = null,
    ): int {
        $user = new User($email, $fullName, $role);

        $violations = $this->validator->validate($user);
        if (\count($violations) > 0) {
            foreach ($violations as $violation) {
                $io->error(\sprintf('%s: %s', $violation->getPropertyPath(), $violation->getMessage()));
            }

            return Command::FAILURE;
        }

        $password ??= $io->askHidden('Password');
        if (!\is_string($password) || mb_strlen($password) < self::MIN_PASSWORD_LENGTH) {
            $io->error(\sprintf('The password must be at least %d characters long.', self::MIN_PASSWORD_LENGTH));

            return Command::FAILURE;
        }

        $user->setPassword($this->passwordHasher->hashPassword($user, $password));
        $this->users->save($user);

        $io->success(\sprintf('User %s created.', $user->getEmail()));

        return Command::SUCCESS;
    }
}
