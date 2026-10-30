<?php

namespace App\Partner\Vies;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The SOAP client of the VIES checkVatService.
 *
 * The WSDL is a copy kept in the repository (config/soap/checkVatService.wsdl), not fetched from the
 * Commission's server: starting the application does not depend on that server being up, and the
 * contract the code relies on is visible and versioned. The WSDL names a plain http:// address; the
 * endpoint is set separately, to https.
 *
 * Not final: tests replace the network call (__doRequest) with recorded answers.
 */
class ViesSoapClient extends \SoapClient
{
    /** Seconds; VIES asks the member state's own register, which can be slow. */
    private const int TIMEOUT = 5;

    public function __construct(
        #[Autowire('%kernel.project_dir%/config/soap/checkVatService.wsdl')]
        string $wsdl,
        #[Autowire('%env(VIES_ENDPOINT)%')]
        string $endpoint,
    ) {
        parent::__construct($wsdl, [
            'location' => $endpoint,
            'connection_timeout' => self::TIMEOUT,
            // Answers time out too, not only connecting
            'stream_context' => stream_context_create(['http' => ['timeout' => self::TIMEOUT]]),
            'exceptions' => true,
            'cache_wsdl' => \WSDL_CACHE_MEMORY,
            'user_agent' => 'System ERP',
        ]);
    }
}
