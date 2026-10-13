<?php

namespace App\Tests\Identity;

use App\Identity\Entity\User;
use App\Identity\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class LoginTest extends WebTestCase
{
    private const string EMAIL = 'anna@example.com';
    private const string PASSWORD = 'correct-horse';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();

        $user = new User(self::EMAIL, 'Anna Nowak', [User::ROLE_SALES]);
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $user->setPassword($hasher->hashPassword($user, self::PASSWORD));
        self::getContainer()->get(UserRepository::class)->save($user);
    }

    public function testAnonymousVisitorIsSentToLogin(): void
    {
        $this->client->request('GET', '/');

        self::assertResponseRedirects('/login');
    }

    public function testUserSignsInAndSeesDashboard(): void
    {
        $this->submitLogin(self::PASSWORD);

        self::assertResponseRedirects('/');
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-test="current-user"]', 'Anna Nowak');
    }

    public function testWrongPasswordShowsError(): void
    {
        $this->submitLogin('wrong-password');

        self::assertResponseRedirects('/login');
        $this->client->followRedirect();
        self::assertSelectorTextContains('[role="alert"]', 'Nieprawidłowe dane');
        self::assertInputValueSame('_username', self::EMAIL);
    }

    public function testSignedInUserCanSignOut(): void
    {
        $this->submitLogin(self::PASSWORD);
        $this->client->followRedirect();

        $this->client->submitForm('Wyloguj');
        $this->client->request('GET', '/');

        self::assertResponseRedirects('/login');
    }

    private function submitLogin(string $password): void
    {
        $this->client->request('GET', '/login');
        $this->client->submitForm('Zaloguj', [
            '_username' => self::EMAIL,
            '_password' => $password,
        ]);
    }
}
