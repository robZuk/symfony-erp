<?php

namespace App\Tests\Identity;

use App\Identity\Entity\User;
use App\Identity\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class CreateUserCommandTest extends KernelTestCase
{
    private CommandTester $tester;

    protected function setUp(): void
    {
        $application = new Application(self::bootKernel());
        $this->tester = new CommandTester($application->find('app:user:create'));
    }

    public function testCreatesUserWithHashedPasswordAndRoles(): void
    {
        $exitCode = $this->tester->execute([
            'email' => 'Jan@Example.com',
            'full-name' => 'Jan Kowalski',
            '--role' => [User::ROLE_WAREHOUSE],
            '--password' => 'secret-password',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);

        $user = self::getContainer()->get(UserRepository::class)->findOneBy(['email' => 'jan@example.com']);
        self::assertInstanceOf(User::class, $user);
        self::assertSame('Jan Kowalski', $user->getFullName());
        self::assertSame([User::ROLE_WAREHOUSE, 'ROLE_USER'], $user->getRoles());
        self::assertTrue(
            self::getContainer()->get(UserPasswordHasherInterface::class)->isPasswordValid($user, 'secret-password'),
        );
    }

    public function testRejectsUnknownRole(): void
    {
        $exitCode = $this->tester->execute([
            'email' => 'jan@example.com',
            'full-name' => 'Jan Kowalski',
            '--role' => ['ROLE_SUPERHERO'],
            '--password' => 'secret-password',
        ]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertNull(self::getContainer()->get(UserRepository::class)->findOneBy(['email' => 'jan@example.com']));
    }

    public function testRejectsDuplicateEmail(): void
    {
        $input = ['email' => 'jan@example.com', 'full-name' => 'Jan Kowalski', '--password' => 'secret-password'];
        $this->tester->execute($input);

        self::assertSame(Command::FAILURE, $this->tester->execute($input));
    }

    public function testRejectsShortPassword(): void
    {
        $exitCode = $this->tester->execute([
            'email' => 'jan@example.com',
            'full-name' => 'Jan Kowalski',
            '--password' => 'short',
        ]);

        self::assertSame(Command::FAILURE, $exitCode);
    }
}
