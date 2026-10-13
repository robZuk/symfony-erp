<?php

namespace App\Identity\Service;

use App\Identity\Entity\User;
use App\Identity\Repository\UserRepository;
use App\Shared\Audit\ActivityLog;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Everything an administrator can do to user accounts, with the rules that keep the system
 * from locking everybody out: an administrator cannot block or demote themselves, and there
 * is always at least one active administrator left.
 */
final class UserAdministration
{
    public const int MIN_PASSWORD_LENGTH = 8;

    public function __construct(
        private readonly UserRepository $users,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly ActivityLog $activity,
    ) {
    }

    /**
     * @param list<string> $roles
     *
     * @throws \DomainException when the e-mail is taken or the password too short
     */
    public function create(string $email, string $fullName, array $roles, string $password): User
    {
        $user = new User($email, $fullName, $roles);
        if (null !== $this->users->findOneBy(['email' => $user->getEmail()])) {
            throw new \DomainException('Użytkownik z tym adresem e-mail już istnieje.');
        }

        $this->hashInto($user, $password);
        $this->users->save($user);
        $this->activity->record('identity.user.created', \sprintf('Założono konto %s (%s).', $user->getFullName(), $user->getEmail()), 'identity_user_edit', ['id' => (int) $user->getId()]);

        return $user;
    }

    /**
     * @param list<string> $roles
     *
     * @throws \DomainException when the change would remove the last administrator or the actor's own admin role
     */
    public function update(User $user, string $fullName, array $roles, User $actor): void
    {
        $losesAdmin = $user->isAdmin() && !\in_array(User::ROLE_ADMIN, $roles, true);
        if ($losesAdmin && $user === $actor) {
            throw new \DomainException('Nie możesz odebrać sobie roli administratora.');
        }
        if ($losesAdmin && $user->isActive() && $this->activeAdministrators() <= 1) {
            throw new \DomainException('W systemie musi zostać przynajmniej jeden aktywny administrator.');
        }

        $user->rename($fullName);
        $user->assignRoles($roles);
        $this->users->save($user);
        $this->activity->record('identity.user.updated', \sprintf('Zmieniono dane konta %s.', $user->getEmail()), 'identity_user_edit', ['id' => (int) $user->getId()]);
    }

    /**
     * Sets a new password without knowing the old one; the user is signed out everywhere.
     *
     * @throws \DomainException when the password is too short
     */
    public function resetPassword(User $user, string $password): void
    {
        $this->hashInto($user, $password);
        $this->users->save($user);
        $this->activity->record('identity.user.password_reset', \sprintf('Ustawiono nowe hasło dla konta %s.', $user->getEmail()), 'identity_user_edit', ['id' => (int) $user->getId()]);
    }

    /**
     * @throws \DomainException when the actor blocks themselves or the last active administrator
     */
    public function block(User $user, User $actor): void
    {
        if ($user === $actor) {
            throw new \DomainException('Nie możesz zablokować własnego konta.');
        }
        if ($user->isAdmin() && $user->isActive() && $this->activeAdministrators() <= 1) {
            throw new \DomainException('W systemie musi zostać przynajmniej jeden aktywny administrator.');
        }

        $user->block();
        $this->users->save($user);
        $this->activity->record('identity.user.blocked', \sprintf('Zablokowano konto %s.', $user->getEmail()), 'identity_user_edit', ['id' => (int) $user->getId()]);
    }

    public function unblock(User $user): void
    {
        $user->unblock();
        $this->users->save($user);
        $this->activity->record('identity.user.unblocked', \sprintf('Odblokowano konto %s.', $user->getEmail()), 'identity_user_edit', ['id' => (int) $user->getId()]);
    }

    /**
     * A user changing their own password. Whether they know the current one is checked by the form.
     *
     * @throws \DomainException when the password is too short
     */
    public function changeOwnPassword(User $user, string $password): void
    {
        $this->hashInto($user, $password);
        $this->users->save($user);
        $this->activity->record('identity.user.password_changed', 'Zmieniono własne hasło.');
    }

    private function hashInto(User $user, string $password): void
    {
        if (mb_strlen($password) < self::MIN_PASSWORD_LENGTH) {
            throw new \DomainException(\sprintf('Hasło musi mieć co najmniej %d znaków.', self::MIN_PASSWORD_LENGTH));
        }

        $user->setPassword($this->passwordHasher->hashPassword($user, $password));
    }

    private function activeAdministrators(): int
    {
        // Roles are a JSON list; with a handful of users it is simplest to count in PHP
        return \count(array_filter(
            $this->users->findBy(['active' => true]),
            static fn (User $user): bool => $user->isAdmin(),
        ));
    }
}
