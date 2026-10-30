<?php

namespace App\Tests\Partner;

use App\Partner\Vies\VatRegistry;
use App\Partner\Vies\ViesSoapClient;
use App\Partner\Vies\ViesUnavailable;
use App\Tests\Support\FakeViesSoapClient;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ViesVatRegistryTest extends KernelTestCase
{
    public function testRegisteredNumberWithNameAndAddress(): void
    {
        $soap = $this->answer(FakeViesSoapClient::valid('5260250274', 'HURTOWNIA ELEKTRO SP. Z O.O.', "UL. PRZYKŁADOWA 1\n00-001 WARSZAWA"));

        $check = $this->registry()->check('pl', '526-025-02-74');

        self::assertTrue($check->valid);
        self::assertSame('PL', $check->countryCode);
        self::assertSame('5260250274', $check->vatNumber);
        self::assertSame('HURTOWNIA ELEKTRO SP. Z O.O.', $check->name);
        self::assertSame('UL. PRZYKŁADOWA 1 00-001 WARSZAWA', $check->address);

        // A document-style checkVat request with the number cleaned of separators
        self::assertCount(1, $soap->requests);
        self::assertStringContainsString('<ns1:checkVat>', $soap->requests[0]);
        self::assertStringContainsString('<ns1:countryCode>PL</ns1:countryCode><ns1:vatNumber>5260250274</ns1:vatNumber>', $soap->requests[0]);
    }

    public function testNumberNotRegisteredForIntraCommunityTrade(): void
    {
        $this->answer(FakeViesSoapClient::notRegistered('5260250274'));

        $check = $this->registry()->check('PL', '5260250274');

        self::assertFalse($check->valid);
        // "---" is what VIES says when it has nothing to disclose
        self::assertNull($check->name);
        self::assertNull($check->address);
    }

    public function testFaultBecomesAMessageAPersonCanActOn(): void
    {
        $this->answer(FakeViesSoapClient::fault('MS_UNAVAILABLE'));

        $this->expectException(ViesUnavailable::class);
        $this->expectExceptionMessage('Rejestr państwa członkowskiego jest chwilowo niedostępny.');

        $this->registry()->check('PL', '5260250274');
    }

    public function testAnswersAreCachedButFailuresAreNot(): void
    {
        $soap = $this->answer(FakeViesSoapClient::fault('SERVICE_UNAVAILABLE'), FakeViesSoapClient::valid('5260250274', 'FIRMA', 'ADRES'));
        $registry = $this->registry();

        try {
            $registry->check('PL', '5260250274');
            self::fail('The first attempt should have failed.');
        } catch (ViesUnavailable) {
        }

        // The failure was not kept: this asks VIES again, and its answer is kept
        self::assertTrue($registry->check('PL', '5260250274')->valid);
        self::assertTrue($registry->check('PL', '5260250274')->valid);
        self::assertCount(2, $soap->requests);
    }

    private function answer(string ...$answers): FakeViesSoapClient
    {
        $soap = new FakeViesSoapClient((string) self::getContainer()->getParameter('kernel.project_dir'), ...$answers);
        self::getContainer()->set(ViesSoapClient::class, $soap);

        return $soap;
    }

    private function registry(): VatRegistry
    {
        return self::getContainer()->get(VatRegistry::class);
    }
}
