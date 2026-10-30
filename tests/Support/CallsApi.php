<?php

namespace App\Tests\Support;

use App\Identity\Entity\ApiKey;
use App\Identity\Entity\ApiScope;
use App\Identity\Repository\ApiKeyRepository;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * REST API requests in tests: a key with the given scopes, and JSON in and out.
 */
trait CallsApi
{
    /**
     * @param list<ApiScope> $scopes
     *
     * @return string the secret, as the client would send it
     */
    private function createApiKey(array $scopes, string $name = 'Sklep testowy'): string
    {
        [$key, $secret] = ApiKey::issue($name, $scopes, new \DateTimeImmutable());
        self::getContainer()->get(ApiKeyRepository::class)->save($key);

        return $secret;
    }

    /**
     * @param array<string, mixed>|null $body    sent as JSON
     * @param array<string, string>     $headers extra request headers, e.g. Idempotency-Key
     *
     * @return array<string, mixed> the decoded JSON response
     */
    private function api(KernelBrowser $client, string $method, string $uri, ?string $key, ?array $body = null, array $headers = []): array
    {
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
        if (null !== $key) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer '.$key;
        }
        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        $client->request($method, $uri, server: $server, content: null === $body ? null : json_encode($body, \JSON_THROW_ON_ERROR));
        $content = (string) $client->getResponse()->getContent();

        /** @var array<string, mixed> $decoded */
        $decoded = '' === $content ? [] : json_decode($content, true, flags: \JSON_THROW_ON_ERROR);

        return $decoded;
    }
}
