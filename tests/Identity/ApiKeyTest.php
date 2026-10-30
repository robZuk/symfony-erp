<?php

namespace App\Tests\Identity;

use App\Identity\Entity\ApiKey;
use App\Identity\Entity\ApiScope;
use App\Identity\Repository\ApiKeyRepository;
use App\Tests\Support\CallsApi;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class ApiKeyTest extends WebTestCase
{
    use CallsApi;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    public function testOnlyTheHashOfTheKeyIsStored(): void
    {
        [$key, $secret] = ApiKey::issue('Sklep', [ApiScope::CatalogRead], new \DateTimeImmutable());
        self::getContainer()->get(ApiKeyRepository::class)->save($key);

        $row = self::getContainer()->get('doctrine.dbal.default_connection')
            ->fetchAssociative('SELECT token_hash, display_prefix FROM identity_api_key WHERE id = ?', [$key->getId()]);

        self::assertIsArray($row);
        self::assertSame(hash('sha256', $secret), $row['token_hash']);
        self::assertStringStartsWith($row['display_prefix'], $secret);
        self::assertStringNotContainsString($secret, (string) json_encode($row));
    }

    public function testRequestWithoutKeyIsChallenged(): void
    {
        $problem = $this->api($this->client, 'GET', '/api/products', null);

        self::assertResponseStatusCodeSame(401);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');
        self::assertStringStartsWith('Bearer', (string) $this->client->getResponse()->headers->get('WWW-Authenticate'));
        self::assertSame(401, $problem['status']);
    }

    public function testWrongAndRevokedKeysAreRefused(): void
    {
        $this->api($this->client, 'GET', '/api/products', 'erp_000000');
        self::assertResponseStatusCodeSame(401);
        self::assertStringContainsString('invalid_token', (string) $this->client->getResponse()->headers->get('WWW-Authenticate'));

        $secret = $this->createApiKey([ApiScope::CatalogRead]);
        $this->api($this->client, 'GET', '/api/products', $secret);
        self::assertResponseIsSuccessful();

        $key = self::getContainer()->get(ApiKeyRepository::class)->findUsable($secret);
        self::assertInstanceOf(ApiKey::class, $key);
        $key->revoke(new \DateTimeImmutable());
        self::getContainer()->get(ApiKeyRepository::class)->save($key);

        $this->api($this->client, 'GET', '/api/products', $secret);
        self::assertResponseStatusCodeSame(401);
    }

    public function testKeyWithoutTheScopeIsForbiddenWithoutNamingRoles(): void
    {
        $problem = $this->api($this->client, 'GET', '/api/products', $this->createApiKey([ApiScope::StockRead]));

        self::assertResponseStatusCodeSame(403);
        self::assertIsString($problem['detail']);
        self::assertStringNotContainsString('ROLE_', $problem['detail']);
    }

    public function testTooManyRequestsAreRefusedUntilLater(): void
    {
        $secret = $this->createApiKey([ApiScope::CatalogRead]);

        // The test environment allows 10 requests a minute
        for ($i = 1; $i <= 10; ++$i) {
            $this->api($this->client, 'GET', '/api/products', $secret);
            self::assertResponseIsSuccessful();
            self::assertResponseHeaderSame('X-RateLimit-Remaining', (string) (10 - $i));
        }

        $this->api($this->client, 'GET', '/api/products', $secret);

        self::assertResponseStatusCodeSame(429);
        self::assertGreaterThan(0, (int) $this->client->getResponse()->headers->get('Retry-After'));

        // Another key has a limit of its own
        $this->api($this->client, 'GET', '/api/products', $this->createApiKey([ApiScope::CatalogRead]));
        self::assertResponseIsSuccessful();
    }

    public function testCommandHandsOverAWorkingKeyOnce(): void
    {
        $command = new CommandTester((new Application(self::$kernel ?? throw new \LogicException()))->find('app:api-key:create'));

        $command->execute(['name' => 'Porównywarka cen', '--scope' => ['catalog:read']]);

        $command->assertCommandIsSuccessful();
        preg_match('/^(erp_[0-9a-f]{48})$/m', $command->getDisplay(), $match);
        $secret = $match[1] ?? self::fail('The command did not print the key.');
        $this->api($this->client, 'GET', '/api/products', $secret);
        self::assertResponseIsSuccessful();
    }

    public function testCommandRefusesAnUnknownScope(): void
    {
        $command = new CommandTester((new Application(self::$kernel ?? throw new \LogicException()))->find('app:api-key:create'));

        self::assertSame(1, $command->execute(['name' => 'Sklep', '--scope' => ['everything']]));
    }

    public function testDocumentationIsPublic(): void
    {
        $this->client->request('GET', '/api/doc.json');

        self::assertResponseIsSuccessful();
        $paths = array_keys(json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR)['paths']);
        self::assertContains('/api/orders', $paths);
        self::assertContains('/api/stock', $paths);
    }
}
