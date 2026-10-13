<?php

namespace App\Tests\Identity;

use App\Identity\Entity\User;
use App\Identity\Repository\UserRepository;
use App\Identity\Service\UserAdministration;
use App\Tests\Support\SignsIn;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Symfony\Component\DomCrawler\Form;

final class UserAdminTest extends WebTestCase
{
    use SignsIn;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    public function testAdministratorCreatesAnAccountThatCanSignIn(): void
    {
        $this->signInAs($this->client, User::ROLE_ADMIN);

        $crawler = $this->client->request('GET', '/admin/users/new');
        $form = $crawler->selectButton('Załóż konto')->form([
            'user[fullName]' => 'Anna Nowak',
            'user[email]' => 'Anna@Example.com',
            'user[password]' => 'secret-password',
        ]);
        $this->tickRoles($form, User::ROLE_SALES, User::ROLE_ACCOUNTING);
        $this->client->submit($form);

        self::assertResponseRedirects('/admin/users');
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('[data-test="flash"]', 'Założono konto anna@example.com.');
        $row = $crawler->filter('[data-test="user-row"]')->reduce(static fn ($node): bool => str_contains($node->text(), 'Anna Nowak'));
        self::assertCount(1, $row);
        self::assertStringContainsString('Handlowiec', $row->text());
        self::assertStringContainsString('Księgowość', $row->text());

        // The new person signs in with the password they were given
        $this->closeTheBrowser();
        $this->signInThroughTheForm('anna@example.com', 'secret-password');
        self::assertResponseRedirects('/');
        $this->client->followRedirect();
        self::assertSelectorTextContains('[data-test="current-user"]', 'Anna Nowak');
        // Not an administrator: no admin menu, no admin pages
        self::assertSelectorTextNotContains('[data-slot="sidebar"]', 'Użytkownicy');
        $this->client->request('GET', '/admin/users');
        self::assertResponseStatusCodeSame(403);
    }

    public function testFormValidatesEmailRolesAndPassword(): void
    {
        $this->signInAs($this->client, User::ROLE_ADMIN);

        $this->client->request('GET', '/admin/users/new');
        $this->client->submitForm('Załóż konto', [
            'user[fullName]' => '',
            'user[email]' => 'not-an-email',
            'user[password]' => 'short',
        ]);

        self::assertResponseStatusCodeSame(422);
        $messages = $this->client->getCrawler()->filter('[data-slot="form-message"]')->each(static fn ($node): string => $node->text());
        self::assertContains('Podaj imię i nazwisko.', $messages);
        self::assertContains('To nie jest poprawny adres e-mail.', $messages);
        self::assertContains('Hasło musi mieć co najmniej 8 znaków.', $messages);
    }

    public function testBlockedUserCannotSignInAndIsSignedOut(): void
    {
        $administration = self::getContainer()->get(UserAdministration::class);
        $administration->create('admin@example.com', 'Adam Admin', [User::ROLE_ADMIN], 'admin-password');
        $anna = $administration->create('anna@example.com', 'Anna Nowak', [User::ROLE_SALES], 'secret-password');

        // Anna is working when the administrator blocks her
        $this->client->loginUser($anna);
        $this->client->request('GET', '/');
        self::assertResponseIsSuccessful();
        $this->administration()->block($this->user('anna@example.com'), $this->user('admin@example.com'));

        $this->client->request('GET', '/');
        self::assertResponseRedirects('/login');

        $this->signInThroughTheForm('anna@example.com', 'secret-password');
        $this->client->followRedirect();
        self::assertSelectorTextContains('[role="alert"]', 'To konto jest zablokowane.');

        // A wrong password gives nothing away about the account's state
        $this->signInThroughTheForm('anna@example.com', 'wrong-password');
        $this->client->followRedirect();
        self::assertSelectorTextNotContains('[role="alert"]', 'zablokowane');

        $this->administration()->unblock($this->user('anna@example.com'));
        $this->signInThroughTheForm('anna@example.com', 'secret-password');
        self::assertResponseRedirects('/');
    }

    public function testAdministratorBlocksAndUnblocksFromTheList(): void
    {
        $administration = self::getContainer()->get(UserAdministration::class);
        $administration->create('anna@example.com', 'Anna Nowak', [User::ROLE_SALES], 'secret-password');
        $this->signInAs($this->client, User::ROLE_ADMIN);

        $crawler = $this->client->request('GET', '/admin/users?q=anna');
        self::assertSelectorCount(1, '[data-test="user-row"]');
        $this->client->submit($crawler->selectButton('Zablokuj')->form());
        $this->client->followRedirect();
        self::assertSelectorTextContains('[data-test="flash"]', 'Zablokowano konto anna@example.com.');

        $this->client->request('GET', '/admin/users?q=anna');
        self::assertSelectorCount(0, '[data-test="user-row"]');

        $crawler = $this->client->request('GET', '/admin/users?status=blocked');
        self::assertSelectorTextContains('[data-test="user-row"]', 'Zablokowany');
        $this->client->submit($crawler->selectButton('Odblokuj')->form());
        $this->client->followRedirect();
        self::assertSelectorTextContains('[data-test="flash"]', 'Odblokowano konto anna@example.com.');
    }

    public function testAdministratorCannotBlockOrDemoteThemselves(): void
    {
        $admin = $this->signInAs($this->client, User::ROLE_ADMIN);

        // The list offers no "block" button on the administrator's own row
        $crawler = $this->client->request('GET', '/admin/users');
        self::assertSelectorTextContains('[data-test="user-row"]', '(Ty)');
        self::assertCount(0, $crawler->selectButton('Zablokuj'));

        $crawler = $this->client->request('GET', \sprintf('/admin/users/%d/edit', $admin->getId()));
        $form = $crawler->selectButton('Zapisz')->form();
        $this->tickRoles($form, User::ROLE_SALES);
        $this->client->submit($form);

        self::assertSelectorTextContains('[data-test="flash"]', 'Nie możesz odebrać sobie roli administratora.');
        $reloaded = self::getContainer()->get(UserRepository::class)->find($admin->getId());
        self::assertInstanceOf(User::class, $reloaded);
        self::assertTrue($reloaded->isAdmin());
    }

    public function testAdministratorSetsANewPasswordForSomeone(): void
    {
        $anna = self::getContainer()->get(UserAdministration::class)->create('anna@example.com', 'Anna Nowak', [], 'secret-password');
        $this->signInAs($this->client, User::ROLE_ADMIN);

        $this->client->request('GET', \sprintf('/admin/users/%d/edit', $anna->getId()));
        $this->client->submitForm('Ustaw hasło', [
            'new_password[password][first]' => 'brand-new-password',
            'new_password[password][second]' => 'something-else',
        ]);
        $this->client->followRedirect();
        self::assertSelectorTextContains('[data-test="flash"]', 'Oba hasła muszą być takie same.');

        $this->client->submitForm('Ustaw hasło', [
            'new_password[password][first]' => 'brand-new-password',
            'new_password[password][second]' => 'brand-new-password',
        ]);
        $this->client->followRedirect();
        self::assertSelectorTextContains('[data-test="flash"]', 'Ustawiono nowe hasło dla anna@example.com.');

        $this->closeTheBrowser();
        $this->signInThroughTheForm('anna@example.com', 'secret-password');
        self::assertResponseRedirects('/login');
        $this->signInThroughTheForm('anna@example.com', 'brand-new-password');
        self::assertResponseRedirects('/');
    }

    public function testUserChangesTheirOwnPasswordAndStaysSignedIn(): void
    {
        self::getContainer()->get(UserAdministration::class)->create('anna@example.com', 'Anna Nowak', [User::ROLE_SALES], 'secret-password');
        $this->signInThroughTheForm('anna@example.com', 'secret-password');

        $this->client->request('GET', '/account');
        $this->client->submitForm('Zmień hasło', [
            'new_password[current]' => 'not-my-password',
            'new_password[password][first]' => 'brand-new-password',
            'new_password[password][second]' => 'brand-new-password',
        ]);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('[data-slot="form-message"]', 'To nie jest Twoje obecne hasło.');

        $this->client->submitForm('Zmień hasło', [
            'new_password[current]' => 'secret-password',
            'new_password[password][first]' => 'brand-new-password',
            'new_password[password][second]' => 'brand-new-password',
        ]);
        self::assertResponseRedirects('/account');
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-test="flash"]', 'Hasło zostało zmienione.');

        $this->closeTheBrowser();
        $this->signInThroughTheForm('anna@example.com', 'brand-new-password');
        self::assertResponseRedirects('/');
    }

    public function testAdminPagesAreClosedToEveryoneElse(): void
    {
        $this->signInAs($this->client, User::ROLE_SALES, User::ROLE_WAREHOUSE, User::ROLE_ACCOUNTING);

        foreach (['/admin/users', '/admin/users/new', '/admin/company', '/admin/activity'] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseStatusCodeSame(403, $url);
        }

        // "My account" is for everybody
        $this->client->request('GET', '/account');
        self::assertResponseIsSuccessful();
    }

    /**
     * Services and entities are fetched afresh after every request, because a request ends the database session.
     */
    private function administration(): UserAdministration
    {
        return self::getContainer()->get(UserAdministration::class);
    }

    private function user(string $email): User
    {
        $user = self::getContainer()->get(UserRepository::class)->findOneBy(['email' => $email]);
        self::assertInstanceOf(User::class, $user);

        return $user;
    }

    /**
     * Ticks exactly the given role checkboxes, whatever was ticked before.
     */
    private function tickRoles(Form $form, string ...$roles): void
    {
        $checkboxes = $form->get('user[roles]');
        self::assertIsArray($checkboxes);

        foreach ($checkboxes as $checkbox) {
            self::assertInstanceOf(ChoiceFormField::class, $checkbox);
            \in_array($checkbox->availableOptionValues()[0], $roles, true) ? $checkbox->tick() : $checkbox->untick();
        }
    }

    /**
     * Forgets the session cookie, as if the user came back with a fresh browser.
     */
    private function closeTheBrowser(): void
    {
        $this->client->getCookieJar()->clear();
    }

    private function signInThroughTheForm(string $email, string $password): void
    {
        $this->client->request('GET', '/login');
        $this->client->submitForm('Zaloguj', ['_username' => $email, '_password' => $password]);
    }
}
