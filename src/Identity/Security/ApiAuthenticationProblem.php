<?php

namespace App\Identity\Security;

use App\Shared\Api\ProblemDetails;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authentication\AuthenticationFailureHandlerInterface;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

/**
 * The 401 answers of the REST API, as RFC 6750 (Bearer tokens) describes them: a
 * "WWW-Authenticate: Bearer" header that says what is wrong, and a problem+json body that says it in words.
 *
 *  - no key at all: the firewall's entry point;
 *  - a key that does not exist or was revoked: the access_token failure handler.
 */
final class ApiAuthenticationProblem implements AuthenticationEntryPointInterface, AuthenticationFailureHandlerInterface
{
    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        return ProblemDetails::create(
            Response::HTTP_UNAUTHORIZED,
            'Brak klucza API. Wyślij go w nagłówku „Authorization: Bearer <klucz>”.',
            headers: ['WWW-Authenticate' => 'Bearer realm="api"'],
        );
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        return ProblemDetails::create(
            Response::HTTP_UNAUTHORIZED,
            'Nieprawidłowy albo unieważniony klucz API.',
            headers: ['WWW-Authenticate' => 'Bearer realm="api", error="invalid_token"'],
        );
    }
}
