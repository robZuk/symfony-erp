<?php

namespace App\Tests\Identity;

use App\Identity\Entity\User;
use App\Identity\Repository\UserRepository;
use App\Identity\Service\UserAdministration;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class UserAdministrationTest extends KernelTestCase
{
    private UserAdministration $administration;
    private User $admin;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->administration = self::getContainer()->get(UserAdministration::class);
        $this->admin = $this->administration->create('admin@example.com', 'Adam Admin', [User::ROLE_ADMIN], 'admin-password');
    }

    public function testCreatesAnAccountWithAHashedPassword(): void
    {
        $user = $this->administration->create(' Anna@Example.com ', 'Anna Nowak', [User::ROLE_SALES], 'secret-password');

        self::assertSame('anna@example.com', $user->getEmail());
        self::assertSame([User::ROLE_SALES], $user->getAssignedRoles());
        self::assertTrue($user->isActive());
        self::assertNotSame('secret-password', $user->getPassword());
        self::assertTrue(self::getContainer()->get(UserPasswordHasherInterface::class)->isPasswordValid($user, 'secret-password'));
    }

    public function testEmailMustBeUniqueAndPasswordLongEnough(): void
    {
        $this->assertRefused(
            fn () => $this->administration->create('ADMIN@example.com', 'Drugi', [], 'secret-password'),
            'Użytkownik z tym adresem e-mail już istnieje.',
        );
        $this->assertRefused(
            fn () => $this->administration->create('anna@example.com', 'Anna', [], 'short'),
            'Hasło musi mieć co najmniej 8 znaków.',
        );
        self::assertNull(self::getContainer()->get(UserRepository::class)->findOneBy(['email' => 'anna@example.com']));
    }

    public function testAdministratorCannotLockThemselvesOut(): void
    {
        $this->assertRefused(fn () => $this->administration->block($this->admin, $this->admin), 'Nie możesz zablokować własnego konta.');
        $this->assertRefused(
            fn () => $this->administration->update($this->admin, 'Adam Admin', [User::ROLE_SALES], $this->admin),
            'Nie możesz odebrać sobie roli administratora.',
        );

        // Changing their own name or adding a role is fine
        $this->administration->update($this->admin, 'Adam Nowy', [User::ROLE_ADMIN, User::ROLE_SALES], $this->admin);
        self::assertSame('Adam Nowy', $this->admin->getFullName());
        self::assertTrue($this->admin->isActive());
    }

    public function testTheLastActiveAdministratorIsProtected(): void
    {
        $second = $this->administration->create('second@example.com', 'Drugi Admin', [User::ROLE_ADMIN], 'secret-password');

        // With two administrators one of them can go
        $this->administration->block($second, $this->admin);
        self::assertFalse($second->isActive());

        // The blocked one does not count: the remaining administrator cannot be blocked or demoted by anybody
        $this->administration->unblock($second);
        $this->administration->block($this->admin, $second);
        $this->assertRefused(fn () => $this->administration->block($second, $this->admin), 'W systemie musi zostać przynajmniej jeden aktywny administrator.');
        $this->assertRefused(
            fn () => $this->administration->update($second, 'Drugi Admin', [], $this->admin),
            'W systemie musi zostać przynajmniej jeden aktywny administrator.',
        );
        self::assertTrue($second->isAdmin());
    }

    public function testChangingPasswordRolesOrBlockingEndsTheUsersSession(): void
    {
        $user = $this->administration->create('anna@example.com', 'Anna Nowak', [User::ROLE_SALES], 'secret-password');
        $inSession = clone $user;
        self::assertTrue($inSession->isEqualTo($user));

        $this->administration->resetPassword($user, 'another-password');
        self::assertFalse($inSession->isEqualTo($user));

        $inSession = clone $user;
        $this->administration->update($user, 'Anna Nowak', [User::ROLE_WAREHOUSE], $this->admin);
        self::assertFalse($inSession->isEqualTo($user));

        $inSession = clone $user;
        $this->administration->block($user, $this->admin);
        self::assertFalse($inSession->isEqualTo($user));

        // A new name alone is no reason to sign anybody out
        $this->administration->unblock($user);
        $inSession = clone $user;
        $this->administration->update($user, 'Anna Kowalska', [User::ROLE_WAREHOUSE], $this->admin);
        self::assertTrue($inSession->isEqualTo($user));
    }

    private function assertRefused(callable $step, string $reason): void
    {
        try {
            $step();
            self::fail('The step should have been refused.');
        } catch (\DomainException $exception) {
            self::assertSame($reason, $exception->getMessage());
        }
    }
}
