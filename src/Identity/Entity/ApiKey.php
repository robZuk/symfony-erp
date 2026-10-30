<?php

namespace App\Identity\Entity;

use App\Identity\Repository\ApiKeyRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A key another system uses to call the REST API.
 *
 * The key itself is shown once, when it is created, and never stored: the database keeps its SHA-256
 * hash, so a leaked database gives no working keys. A slow password hash is not needed, because
 * the key is 24 random bytes that no dictionary contains, and a plain hash can be looked up by index
 * on every request. The first characters are kept to tell keys apart on screen and in logs.
 */
#[ORM\Entity(repositoryClass: ApiKeyRepository::class)]
#[ORM\Table(name: 'identity_api_key')]
class ApiKey
{
    private const string PREFIX = 'erp_';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** @var list<string> ApiScope values */
    #[ORM\Column(type: 'json')]
    private array $scopes;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastUsedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

    /**
     * @param list<ApiScope> $scopes
     */
    private function __construct(
        #[ORM\Column(length: 100)]
        private string $name,
        #[ORM\Column(length: 64, unique: true)]
        private string $tokenHash,
        #[ORM\Column(length: 12)]
        private string $displayPrefix,
        array $scopes,
        #[ORM\Column]
        private \DateTimeImmutable $createdAt,
    ) {
        $this->scopes = array_values(array_unique(array_map(static fn (ApiScope $scope): string => $scope->value, $scopes)));
    }

    /**
     * @param list<ApiScope> $scopes
     *
     * @return array{ApiKey, string} the key to save, and the secret to hand over once
     */
    public static function issue(string $name, array $scopes, \DateTimeImmutable $now): array
    {
        $secret = self::PREFIX.bin2hex(random_bytes(24));

        return [new self(trim($name), self::hash($secret), substr($secret, 0, 12), $scopes, $now), $secret];
    }

    public static function hash(string $secret): string
    {
        return hash('sha256', $secret);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    /** E.g. "erp_3f9c1a2b", enough to recognise a key without revealing it. */
    public function getDisplayPrefix(): string
    {
        return $this->displayPrefix;
    }

    /**
     * @return list<ApiScope>
     */
    public function getScopes(): array
    {
        return array_values(array_filter(array_map(ApiScope::tryFrom(...), $this->scopes)));
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getLastUsedAt(): ?\DateTimeImmutable
    {
        return $this->lastUsedAt;
    }

    public function isRevoked(): bool
    {
        return null !== $this->revokedAt;
    }

    public function revoke(\DateTimeImmutable $now): void
    {
        $this->revokedAt ??= $now;
    }

    /**
     * Records a request; at most once a minute, so a busy client does not write on every call.
     *
     * @return bool whether anything changed and needs saving
     */
    public function markUsed(\DateTimeImmutable $now): bool
    {
        if (null !== $this->lastUsedAt && $now < $this->lastUsedAt->modify('+1 minute')) {
            return false;
        }

        $this->lastUsedAt = $now;

        return true;
    }
}
