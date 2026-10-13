<?php

namespace App\Tests\Identity;

use App\Identity\Entity\User;
use App\Identity\Service\UserAdministration;
use App\Shared\Audit\ActivityEntryRepository;
use App\Tests\Support\ForgetsLoginAttempts;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class LoginThrottlingTest extends WebTestCase
{
    use ForgetsLoginAttempts;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->forgetLoginAttempts();

        $administration = self::getContainer()->get(UserAdministration::class);
        $administration->create('anna@example.com', 'Anna Nowak', [User::ROLE_SALES], 'secret-password');
        $administration->create('jan@example.com', 'Jan Kowalski', [User::ROLE_SALES], 'secret-password');
    }

    public function testFiveFailuresLockTheAccountEvenForTheRightPassword(): void
    {
        for ($attempt = 1; $attempt <= 5; ++$attempt) {
            $this->signIn('anna@example.com', 'wrong-password');
            $this->client->followRedirect();
            self::assertSelectorTextContains('[role="alert"]', 'Nieprawidłowe dane.', \sprintf('Attempt %d', $attempt));
        }

        // The sixth attempt is refused whatever the password
        $this->signIn('anna@example.com', 'secret-password');
        self::assertResponseRedirects('/login');
        $this->client->followRedirect();
        self::assertSelectorTextContains('[role="alert"]', 'Zbyt wiele nieudanych prób logowania');

        // Another account from the same address is not affected
        $this->signIn('jan@example.com', 'secret-password');
        self::assertResponseRedirects('/');
    }

    public function testASuccessfulSignInStartsTheCountAgain(): void
    {
        for ($attempt = 1; $attempt <= 4; ++$attempt) {
            $this->signIn('anna@example.com', 'wrong-password');
        }
        $this->signIn('anna@example.com', 'secret-password');
        self::assertResponseRedirects('/');
        $this->client->getCookieJar()->clear();

        // Four more mistakes would have crossed the limit if the earlier ones still counted
        for ($attempt = 1; $attempt <= 4; ++$attempt) {
            $this->signIn('anna@example.com', 'wrong-password');
        }
        $this->signIn('anna@example.com', 'secret-password');
        self::assertResponseRedirects('/');
    }

    public function testLockOutIsLoggedOnceHoweverLongTheAttackGoesOn(): void
    {
        for ($attempt = 1; $attempt <= 12; ++$attempt) {
            $this->signIn('anna@example.com', 'wrong-password');
        }

        $entries = self::getContainer()->get(ActivityEntryRepository::class);
        self::assertCount(5, $entries->findBy(['action' => 'identity.session.sign_in_failed']));
        $locked = $entries->findBy(['action' => 'identity.session.sign_in_locked']);
        self::assertCount(1, $locked);
        self::assertSame('Zablokowano logowanie na konto „anna@example.com” po zbyt wielu nieudanych próbach.', $locked[0]->getSummary());
    }

    private function signIn(string $email, string $password): void
    {
        $this->client->request('GET', '/login');
        $this->client->submitForm('Zaloguj', ['_username' => $email, '_password' => $password]);
    }
}
