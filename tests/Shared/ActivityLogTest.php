<?php

namespace App\Tests\Shared;

use App\Identity\Entity\User;
use App\Identity\Service\UserAdministration;
use App\Inventory\Entity\DocumentType;
use App\Shared\Audit\ActivityEntry;
use App\Shared\Audit\ActivityEntryRepository;
use App\Shared\Audit\ActivityLog;
use App\Tests\Support\CreatesRecords;
use App\Tests\Support\ForgetsLoginAttempts;
use App\Tests\Support\SignsIn;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ActivityLogTest extends WebTestCase
{
    use ForgetsLoginAttempts;
    use CreatesRecords;
    use SignsIn;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->forgetLoginAttempts();
    }

    public function testWhatPeopleDoOnTheScreensIsLoggedUnderTheirName(): void
    {
        $warehouse = $this->createWarehouse('MG');
        $cable = $this->createProduct('KAB-1', 'Kabel');
        $draft = $this->createDocument(DocumentType::Receipt, $warehouse, [(int) $cable->getId() => 5000]);
        $user = $this->signInAs($this->client, User::ROLE_ADMIN);

        $this->client->request('GET', \sprintf('/inventory/documents/%d', $draft->getId()));
        $this->client->submitForm('Zaksięguj');

        $entries = $this->entries('inventory.document.posted');
        self::assertCount(1, $entries);
        self::assertSame($user->getId(), $entries[0]->getActorId());
        self::assertSame('Test User', $entries[0]->getActorName());
        self::assertMatchesRegularExpression('#^Zaksięgowano dokument PZ/\d{4}/\d{2}/0001\.$#', $entries[0]->getSummary());

        // The log page shows it with a link to the document
        $crawler = $this->client->request('GET', '/admin/activity');
        self::assertSelectorTextContains('[data-test="activity-row"]', 'Test User');
        $link = $crawler->filter('[data-test="activity-row"] a')->link();
        $this->client->click($link);
        self::assertSelectorTextContains('[data-test="document-status"]', 'Zaksięgowany');
    }

    public function testAnOperationThatWasRefusedLeavesNoTrace(): void
    {
        $warehouse = $this->createWarehouse('MG');
        $cable = $this->createProduct('KAB-1', 'Kabel');
        $issue = $this->createDocument(DocumentType::Issue, $warehouse, [(int) $cable->getId() => 5000]);
        $this->signInAs($this->client, User::ROLE_WAREHOUSE);

        $this->client->request('GET', \sprintf('/inventory/documents/%d', $issue->getId()));
        $this->client->submitForm('Zaksięguj');
        $this->client->followRedirect();

        self::assertSelectorTextContains('[data-test="flash"]', 'Brak towaru KAB-1');
        self::assertCount(0, $this->entries('inventory.document.posted'));
    }

    public function testWorkDoneWithoutASignedInUserIsLoggedAsSystem(): void
    {
        $warehouse = $this->createWarehouse('MG');
        $cable = $this->createProduct('KAB-1', 'Kabel');

        $this->receive($warehouse, [(int) $cable->getId() => 5000]);

        $entries = $this->entries('inventory.document.posted');
        self::assertCount(1, $entries);
        self::assertNull($entries[0]->getActorId());
        self::assertSame(ActivityLog::SYSTEM, $entries[0]->getActorName());
    }

    public function testLogCanBeSearchedByPersonAndText(): void
    {
        $log = self::getContainer()->get(ActivityLog::class);
        $log->record('test.one', 'Zaksięgowano dokument PZ/2026/10/0001.');
        $log->record('test.two', 'Wystawiono fakturę FV/2026/10/0001 dla Hurtownia Elektro.');
        $this->signInAs($this->client, User::ROLE_ADMIN);

        $this->client->request('GET', '/admin/activity?q=faktur');
        self::assertSelectorCount(1, '[data-test="activity-row"]');
        self::assertSelectorTextContains('[data-test="activity-row"]', 'Hurtownia Elektro');

        $this->client->request('GET', '/admin/activity?q=system');
        self::assertSelectorCount(2, '[data-test="activity-row"]');

        $this->client->request('GET', '/admin/activity?q=nic-takiego');
        self::assertSelectorCount(0, '[data-test="activity-row"]');
    }

    public function testEditingMasterDataIsLoggedWithWhatChanged(): void
    {
        $cable = $this->createProduct('KAB-1', 'Kabel', netPrice: 3490);
        $user = $this->signInAs($this->client, User::ROLE_SALES);

        $this->client->request('GET', \sprintf('/catalog/products/%d/edit', $cable->getId()));
        $this->client->submitForm('Zapisz', [
            'product[name]' => 'Kabel HDMI',
            'product[netPrice]' => '39,90',
            'product[vatRate]' => '8',
            'product[minimumStock]' => '12,5',
        ]);

        $entries = $this->entries('catalog.product.updated');
        self::assertCount(1, $entries);
        self::assertSame($user->getId(), $entries[0]->getActorId());
        self::assertSame(
            'Zmieniono produkt KAB-1: nazwa: Kabel → Kabel HDMI; cena netto: 34,90 zł → 39,90 zł; stawka VAT: 23% → 8%; stan minimalny: 0 → 12,5.',
            // The currency formatter separates the amount from "zł" with a non-breaking space
            str_replace("\u{a0}", ' ', $entries[0]->getSummary()),
        );
        self::assertSame('catalog_product_edit', $entries[0]->getRoute());
        self::assertSame(['id' => $cable->getId()], $entries[0]->getRouteParameters());

        // Saving the form again without changing anything is not an event
        $this->client->request('GET', \sprintf('/catalog/products/%d/edit', $cable->getId()));
        $this->client->submitForm('Zapisz');
        self::assertCount(1, $this->entries('catalog.product.updated'));
    }

    public function testCreatingAndWithdrawingMasterDataIsLogged(): void
    {
        $this->signInAs($this->client, User::ROLE_SALES);

        $this->client->request('GET', '/partners/new');
        $this->client->submitForm('Zapisz', [
            'partner[name]' => 'Hurtownia Elektro',
            'partner[customer]' => true,
            'partner[address][street]' => 'Długa 5',
            'partner[address][postalCode]' => '80-001',
            'partner[address][city]' => 'Gdańsk',
        ]);
        $created = $this->entries('partner.partner.created');
        self::assertCount(1, $created);
        self::assertSame('Dodano kontrahenta Hurtownia Elektro.', $created[0]->getSummary());
        self::assertSame('partner_edit', $created[0]->getRoute());
        self::assertGreaterThan(0, $created[0]->getRouteParameters()['id']);

        // An address is stored inside the partner; its fields are reported too
        $this->client->request('GET', \sprintf('/partners/%d/edit', $created[0]->getRouteParameters()['id']));
        $this->client->submitForm('Zapisz', ['partner[address][city]' => 'Sopot', 'partner[nip]' => '123-456-32-18']);
        self::assertSame(
            'Zmieniono kontrahenta Hurtownia Elektro: NIP: (puste) → 1234563218; miejscowość: Gdańsk → Sopot.',
            $this->entries('partner.partner.updated')[0]->getSummary(),
        );

        $this->client->request('GET', '/partners');
        $this->client->submitForm('Archiwizuj');
        self::assertSame(
            'Zmieniono kontrahenta Hurtownia Elektro: aktywny: tak → nie.',
            $this->entries('partner.partner.updated')[1]->getSummary(),
        );
    }

    public function testSignInsFailedAttemptsAndSignOutsAreLogged(): void
    {
        self::getContainer()->get(UserAdministration::class)->create('anna@example.com', 'Anna Nowak', [User::ROLE_SALES], 'secret-password');

        $this->client->request('GET', '/login');
        $this->client->submitForm('Zaloguj', ['_username' => 'anna@example.com', '_password' => 'wrong-password']);
        $failed = $this->entries('identity.session.sign_in_failed');
        self::assertCount(1, $failed);
        self::assertSame('Nieudana próba logowania na konto „anna@example.com”.', $failed[0]->getSummary());
        self::assertSame(ActivityLog::SYSTEM, $failed[0]->getActorName());

        $this->client->followRedirect();
        $this->client->submitForm('Zaloguj', ['_username' => 'anna@example.com', '_password' => 'secret-password']);
        $signedIn = $this->entries('identity.session.signed_in');
        self::assertCount(1, $signedIn);
        self::assertSame('Anna Nowak', $signedIn[0]->getActorName());

        $this->client->followRedirect();
        $this->client->submitForm('Wyloguj');
        $signedOut = $this->entries('identity.session.signed_out');
        self::assertCount(1, $signedOut);
        self::assertSame('Anna Nowak', $signedOut[0]->getActorName());
    }

    public function testDraftsOfDocumentsAreLoggedFromCreationToRemoval(): void
    {
        $warehouse = $this->createWarehouse('MG');
        $cable = $this->createProduct('KAB-1', 'Kabel');
        $this->signInAs($this->client, User::ROLE_WAREHOUSE);

        $this->client->request('GET', '/inventory/documents/new?type=PZ');
        $this->client->submitForm('Utwórz szkic', ['stock_document[warehouse]' => $warehouse->getId()]);
        $this->client->followRedirect();
        $this->client->submitForm('Dodaj pozycję', [
            'stock_document_line[productId]' => $cable->getId(),
            'stock_document_line[quantity]' => '3',
        ]);
        $this->client->followRedirect();
        $this->client->submitForm('Usuń pozycję');
        $this->client->followRedirect();
        $this->client->submitForm('Usuń szkic');

        $summaries = array_map(
            static fn (ActivityEntry $entry): string => preg_replace('/nr \d+/', 'nr N', $entry->getSummary()) ?? '',
            self::getContainer()->get(ActivityEntryRepository::class)->findBy([], ['id' => 'ASC']),
        );
        self::assertSame([
            'Dodano magazyn MG.',
            'Dodano produkt KAB-1.',
            'Utworzono dokument PZ (szkic nr N).',
            'Dodano pozycję KAB-1 do dokumentu PZ (szkic nr N).',
            'Usunięto pozycję KAB-1 z dokumentu PZ (szkic nr N).',
            'Usunięto dokument PZ (szkic nr N).',
        ], $summaries);
    }

    public function testLogCanBeNarrowedToOneArea(): void
    {
        $this->createWarehouse('MG');
        $this->createProduct('KAB-1', 'Kabel');
        $this->signInAs($this->client, User::ROLE_ADMIN);

        $this->client->request('GET', '/admin/activity');
        self::assertSelectorCount(2, '[data-test="activity-row"]');

        $this->client->request('GET', '/admin/activity?q=&area=catalog');
        self::assertSelectorCount(1, '[data-test="activity-row"]');
        self::assertSelectorTextContains('[data-test="activity-row"]', 'Dodano produkt KAB-1.');

        $this->client->request('GET', '/admin/activity?area=bogus');
        self::assertResponseStatusCodeSame(404);
    }

    /**
     * @return list<ActivityEntry>
     */
    private function entries(string $action): array
    {
        return self::getContainer()->get(ActivityEntryRepository::class)->findBy(['action' => $action], ['id' => 'ASC']);
    }
}
