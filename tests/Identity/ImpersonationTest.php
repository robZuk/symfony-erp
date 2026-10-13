<?php

namespace App\Tests\Identity;

use App\Identity\Entity\User;
use App\Identity\Service\UserAdministration;
use App\Shared\Audit\ActivityEntry;
use App\Shared\Audit\ActivityEntryRepository;
use App\Tests\Support\CreatesRecords;
use App\Tests\Support\SignsIn;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ImpersonationTest extends WebTestCase
{
    use CreatesRecords;
    use SignsIn;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        self::getContainer()->get(UserAdministration::class)->create('anna@example.com', 'Anna Nowak', [User::ROLE_SALES], 'secret-password');
    }

    public function testAdministratorWorksAsAnotherUserAndComesBack(): void
    {
        $this->signInAs($this->client, User::ROLE_ADMIN);

        $crawler = $this->client->request('GET', '/admin/users?q=anna');
        $this->client->click($crawler->selectLink('Zaloguj jako')->link());
        $crawler = $this->client->followRedirect();

        // The administrator now sees the system exactly as Anna does
        self::assertSelectorTextContains('[data-test="impersonation-banner"]', 'Jesteś zalogowany jako Anna Nowak');
        self::assertSelectorTextContains('[data-test="current-user"]', 'Anna Nowak');
        self::assertSelectorTextNotContains('[data-slot="sidebar"]', 'Administracja');
        $this->client->request('GET', '/sales/orders/new');
        self::assertResponseIsSuccessful();
        $this->client->request('GET', '/admin/company');
        self::assertResponseStatusCodeSame(403);

        // The banner is on every page and leads back
        $crawler = $this->client->request('GET', '/catalog/products');
        $this->client->click($crawler->selectLink('Wróć do swojego konta')->link());
        self::assertResponseRedirects('/admin/users');
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('[data-test="impersonation-banner"]');
        self::assertSelectorTextContains('[data-test="current-user"]', 'Test User');
    }

    public function testWhatIsDoneWhileImpersonatingIsLoggedUnderTheAdministrator(): void
    {
        $cable = $this->createProduct('KAB-1', 'Kabel', netPrice: 3490);
        $admin = $this->signInAs($this->client, User::ROLE_ADMIN);

        $this->client->request('GET', '/admin/users?_switch_user=anna@example.com');
        $this->client->request('GET', \sprintf('/catalog/products/%d/edit', $cable->getId()));
        $this->client->submitForm('Zapisz', ['product[netPrice]' => '39,90']);
        $this->client->request('GET', '/?_switch_user=_exit');

        $summaries = array_map(
            static fn (ActivityEntry $entry): string => \sprintf('%s: %s', $entry->getActorName(), str_replace("\u{a0}", ' ', $entry->getSummary())),
            self::getContainer()->get(ActivityEntryRepository::class)->findBy(['actorId' => $admin->getId()], ['id' => 'ASC']),
        );
        self::assertSame([
            'Test User: Zalogowano jako Anna Nowak.',
            'Test User (jako Anna Nowak): Zmieniono produkt KAB-1: cena netto: 34,90 zł → 39,90 zł.',
            'Test User: Wrócono z konta Anna Nowak.',
        ], $summaries);
    }

    public function testOnlyAnAdministratorCanImpersonate(): void
    {
        $this->signInAs($this->client, User::ROLE_SALES, User::ROLE_WAREHOUSE, User::ROLE_ACCOUNTING);

        $this->client->request('GET', '/?_switch_user=anna@example.com');

        self::assertResponseStatusCodeSame(403);
    }

    public function testBlockedAccountCannotBeImpersonated(): void
    {
        $admin = $this->signInAs($this->client, User::ROLE_ADMIN);
        $administration = self::getContainer()->get(UserAdministration::class);
        $anna = self::getContainer()->get('doctrine')->getRepository(User::class)->findOneBy(['email' => 'anna@example.com']);
        self::assertInstanceOf(User::class, $anna);
        $administration->block($anna, $admin);

        // No button on the list...
        $crawler = $this->client->request('GET', '/admin/users?q=anna');
        self::assertCount(0, $crawler->selectLink('Zaloguj jako'));

        // ...and the address typed by hand does not work either
        $this->client->request('GET', '/?_switch_user=anna@example.com');
        self::assertNotSame(200, $this->client->getResponse()->getStatusCode());
        $this->client->request('GET', '/');
        self::assertSelectorNotExists('[data-test="impersonation-banner"]');
    }

    public function testNoButtonForTheAdministratorsOwnRow(): void
    {
        $this->signInAs($this->client, User::ROLE_ADMIN);

        $crawler = $this->client->request('GET', '/admin/users');

        // Two users are listed, one of them the administrator: exactly one can be impersonated
        self::assertSelectorCount(2, '[data-test="user-row"]');
        self::assertCount(1, $crawler->selectLink('Zaloguj jako'));
    }
}
