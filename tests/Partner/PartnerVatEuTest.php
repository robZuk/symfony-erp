<?php

namespace App\Tests\Partner;

use App\Identity\Entity\User;
use App\Partner\Entity\Partner;
use App\Partner\Repository\PartnerRepository;
use App\Partner\Vies\ViesSoapClient;
use App\Tests\Support\CreatesRecords;
use App\Tests\Support\FakeViesSoapClient;
use App\Tests\Support\SignsIn;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PartnerVatEuTest extends WebTestCase
{
    use CreatesRecords;
    use SignsIn;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        // One kernel for the whole test, so the fake SOAP client stays in place across requests
        $this->client->disableReboot();
    }

    public function testCheckIsShownAndKeptOnThePartner(): void
    {
        $partner = $this->createCustomer('Hurtownia Elektro', '5260250274');
        $this->answer(FakeViesSoapClient::valid('5260250274', 'HURTOWNIA ELEKTRO SP. Z O.O.', 'WARSZAWA'));
        $this->signInAs($this->client, User::ROLE_SALES);

        $crawler = $this->client->request('GET', '/partners/'.$partner->getId().'/edit');
        self::assertSelectorTextContains('[data-test="vat-eu"]', 'Jeszcze nie sprawdzano.');

        $this->client->submit($crawler->selectButton('Sprawdź w VIES')->form());
        $this->client->followRedirect();

        self::assertSelectorTextContains('[data-test="flash"]', 'PL5260250274 jest aktywny w VAT-UE jako „HURTOWNIA ELEKTRO SP. Z O.O.”');
        self::assertSelectorTextContains('[data-test="vat-eu"]', 'aktywny VAT-UE');
        $reloaded = $this->reloadPartner($partner);
        self::assertTrue($reloaded->isVatEuValid());
        self::assertSame('HURTOWNIA ELEKTRO SP. Z O.O.', $reloaded->getVatEuName());
        self::assertNotNull($reloaded->getVatEuCheckedAt());
        self::assertSame(1, (int) self::getContainer()->get('doctrine.dbal.default_connection')->fetchOne(
            "SELECT count(*) FROM shared_activity_log WHERE action = 'partner.vat_eu.checked'",
        ));
    }

    public function testNotRegisteredIsExplainedNotAlarming(): void
    {
        $partner = $this->createCustomer('Sklep lokalny', '5260250274');
        $this->answer(FakeViesSoapClient::notRegistered('5260250274'));
        $this->signInAs($this->client, User::ROLE_SALES);

        $this->check($partner);

        self::assertSelectorTextContains('[data-test="flash"]', 'nie jest zarejestrowany do transakcji wewnątrzwspólnotowych');
        self::assertSelectorTextContains('[data-test="flash"]', 'To nie znaczy, że firma nie istnieje');
        self::assertFalse($this->reloadPartner($partner)->isVatEuValid());
    }

    public function testNoAnswerKeepsWhatWasKnown(): void
    {
        $partner = $this->createCustomer('Hurtownia Elektro', '5260250274');
        $this->answer(FakeViesSoapClient::valid('5260250274', 'HURTOWNIA', 'WARSZAWA'), FakeViesSoapClient::fault('TIMEOUT'));
        $this->signInAs($this->client, User::ROLE_SALES);
        $this->check($partner);
        // Forget the cached answer, so that the second check reaches the (now failing) service
        self::getContainer()->get('vies.cache')->clear();

        $this->check($partner);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-test="flash"]', 'nie odpowiedział na czas');
        self::assertTrue($this->reloadPartner($partner)->isVatEuValid());
    }

    public function testPartnerWithoutNipHasNothingToCheck(): void
    {
        $partner = $this->createCustomer('Jan Kowalski');
        $this->signInAs($this->client, User::ROLE_SALES);

        $this->client->request('GET', '/partners/'.$partner->getId().'/edit');

        self::assertSelectorNotExists('[data-test="vat-eu"]');
    }

    public function testCheckNeedsPartnerPermission(): void
    {
        $partner = $this->createCustomer('Hurtownia Elektro', '5260250274');
        $this->signInAs($this->client, User::ROLE_WAREHOUSE);

        $this->client->request('POST', '/partners/'.$partner->getId().'/vat-eu');

        self::assertResponseStatusCodeSame(403);
    }

    private function check(Partner $partner): void
    {
        $crawler = $this->client->request('GET', '/partners/'.$partner->getId().'/edit');
        $this->client->submit($crawler->selectButton('Sprawdź w VIES')->form());
        $this->client->followRedirect();
    }

    private function answer(string ...$answers): void
    {
        self::getContainer()->set(ViesSoapClient::class, new FakeViesSoapClient((string) self::getContainer()->getParameter('kernel.project_dir'), ...$answers));
    }

    private function reloadPartner(Partner $partner): Partner
    {
        $reloaded = self::getContainer()->get(PartnerRepository::class)->find($partner->getId());
        self::assertInstanceOf(Partner::class, $reloaded);
        self::getContainer()->get('doctrine')->getManager()->refresh($reloaded);

        return $reloaded;
    }
}
