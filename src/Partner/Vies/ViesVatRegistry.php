<?php

namespace App\Partner\Vies;

use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * VAT numbers checked in VIES over SOAP (operation checkVat).
 *
 * An answer is kept in Redis for a day: registrations rarely change, and VIES limits how often it
 * may be asked. A failure is not kept, so the next attempt asks again. The SOAP faults VIES
 * documents are turned into messages a person can act on.
 */
final class ViesVatRegistry implements VatRegistry
{
    /** Fault strings of checkVatService, as documented by the Commission. */
    private const array FAULTS = [
        'INVALID_INPUT' => 'VIES odrzucił numer jako błędny.',
        'SERVICE_UNAVAILABLE' => 'VIES jest chwilowo niedostępny.',
        'MS_UNAVAILABLE' => 'Rejestr państwa członkowskiego jest chwilowo niedostępny.',
        'TIMEOUT' => 'Rejestr państwa członkowskiego nie odpowiedział na czas.',
        'MS_MAX_CONCURRENT_REQ' => 'Rejestr państwa członkowskiego jest przeciążony.',
        'GLOBAL_MAX_CONCURRENT_REQ' => 'VIES jest przeciążony.',
        'SERVER_BUSY' => 'VIES jest przeciążony.',
    ];

    public function __construct(
        private readonly ViesSoapClient $soap,
        #[Target('vies.cache')]
        private readonly CacheInterface $cache,
        private readonly ClockInterface $clock,
    ) {
    }

    public function check(string $countryCode, string $vatNumber): VatCheck
    {
        $countryCode = strtoupper(trim($countryCode));
        $vatNumber = preg_replace('/[^0-9A-Za-z]/', '', $vatNumber) ?? '';

        // The callback throws on failure, and the cache then stores nothing
        return $this->cache->get(\sprintf('vies.%s%s', $countryCode, $vatNumber), function (ItemInterface $item) use ($countryCode, $vatNumber): VatCheck {
            $item->expiresAfter(86400);

            return $this->ask($countryCode, $vatNumber);
        });
    }

    private function ask(string $countryCode, string $vatNumber): VatCheck
    {
        try {
            /** @var object{countryCode: string, vatNumber: string, valid: bool, name?: string, address?: string} $answer */
            $answer = $this->soap->__soapCall('checkVat', [['countryCode' => $countryCode, 'vatNumber' => $vatNumber]]);
        } catch (\SoapFault $fault) {
            $code = strtoupper(trim($fault->getMessage()));

            throw new ViesUnavailable(self::FAULTS[$code] ?? 'Nie udało się połączyć z VIES.', 0, $fault);
        }

        return new VatCheck(
            $answer->countryCode,
            $answer->vatNumber,
            $answer->valid,
            self::text($answer->name ?? null),
            self::text($answer->address ?? null),
            $this->clock->now(),
        );
    }

    /**
     * VIES gives "---" where a member state does not disclose the name or address.
     */
    private static function text(?string $value): ?string
    {
        $value = trim(preg_replace('/\s+/', ' ', (string) $value) ?? '');

        return '' === $value || '---' === $value ? null : $value;
    }
}
