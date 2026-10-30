<?php

namespace App\Tests\Support;

use App\Partner\Vies\ViesSoapClient;

/**
 * The real VIES client, the real WSDL, and no network: __doRequest() answers with recorded SOAP
 * responses. The request the client built is kept, so a test can see what would have been sent.
 */
final class FakeViesSoapClient extends ViesSoapClient
{
    /** @var list<string> */
    public array $requests = [];

    /** @var list<string> answers given in turn; the last one is repeated */
    private array $answers;

    public function __construct(string $projectDir, string ...$answers)
    {
        parent::__construct($projectDir.'/config/soap/checkVatService.wsdl', 'https://vies.invalid/checkVatService');
        $this->answers = array_values($answers);
    }

    public static function valid(string $vatNumber, string $name, string $address): string
    {
        return self::envelope(\sprintf(
            '<ns2:checkVatResponse xmlns:ns2="urn:ec.europa.eu:taxud:vies:services:checkVat:types"><ns2:countryCode>PL</ns2:countryCode><ns2:vatNumber>%s</ns2:vatNumber><ns2:requestDate>2026-10-30+01:00</ns2:requestDate><ns2:valid>true</ns2:valid><ns2:name>%s</ns2:name><ns2:address>%s</ns2:address></ns2:checkVatResponse>',
            $vatNumber,
            htmlspecialchars($name, \ENT_XML1),
            htmlspecialchars($address, \ENT_XML1),
        ));
    }

    public static function notRegistered(string $vatNumber): string
    {
        return self::envelope(\sprintf(
            '<ns2:checkVatResponse xmlns:ns2="urn:ec.europa.eu:taxud:vies:services:checkVat:types"><ns2:countryCode>PL</ns2:countryCode><ns2:vatNumber>%s</ns2:vatNumber><ns2:requestDate>2026-10-30+01:00</ns2:requestDate><ns2:valid>false</ns2:valid><ns2:name>---</ns2:name><ns2:address>---</ns2:address></ns2:checkVatResponse>',
            $vatNumber,
        ));
    }

    public static function fault(string $faultString): string
    {
        return self::envelope(\sprintf('<env:Fault><faultcode>env:Server</faultcode><faultstring>%s</faultstring></env:Fault>', $faultString));
    }

    public function __doRequest(string $request, string $location, string $action, int $version, bool $oneWay = false, ?string $uriParserClass = null): string
    {
        $this->requests[] = $request;

        return \count($this->answers) > 1 ? (string) array_shift($this->answers) : (string) ($this->answers[0] ?? '');
    }

    private static function envelope(string $body): string
    {
        return '<env:Envelope xmlns:env="http://schemas.xmlsoap.org/soap/envelope/"><env:Header/><env:Body>'.$body.'</env:Body></env:Envelope>';
    }
}
