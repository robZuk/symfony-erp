<?php

namespace App\Tests\Shared;

use App\Identity\Entity\User;
use App\Inventory\Entity\DocumentType;
use App\Shared\Audit\ActivityEntry;
use App\Shared\Audit\ActivityEntryRepository;
use App\Shared\Audit\ActivityLog;
use App\Tests\Support\CreatesRecords;
use App\Tests\Support\SignsIn;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ActivityLogTest extends WebTestCase
{
    use CreatesRecords;
    use SignsIn;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
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

    /**
     * @return list<ActivityEntry>
     */
    private function entries(string $action): array
    {
        return self::getContainer()->get(ActivityEntryRepository::class)->findBy(['action' => $action], ['id' => 'ASC']);
    }
}
