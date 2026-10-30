<?php

namespace App\Shared\Api;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Validator\ConstraintViolationInterface;
use Symfony\Component\Validator\Exception\ValidationFailedException;

/**
 * Every error of the REST API as "application/problem+json" (RFC 9457), in one shape:
 *
 *   {"type": "about:blank", "title": "Not Found", "status": 404, "detail": "Nie ma produktu o indeksie X."}
 *
 * The detail of an HTTP error (404, 409...) is meant for the client and is shown. A failed validation
 * (422) also lists each field with its message. An unexpected error (500) never reveals what went
 * wrong, not even in development: the log has it, the client gets a generic message.
 */
final class ProblemDetails
{
    // Before the framework's own error rendering, which listens at priority -128
    #[AsEventListener(event: KernelEvents::EXCEPTION, priority: 0)]
    public function onException(ExceptionEvent $event): void
    {
        if (!self::isApi($event->getRequest()->getPathInfo())) {
            return;
        }

        $exception = $event->getThrowable();
        $status = $exception instanceof HttpExceptionInterface ? $exception->getStatusCode() : Response::HTTP_INTERNAL_SERVER_ERROR;
        $headers = $exception instanceof HttpExceptionInterface ? $exception->getHeaders() : [];

        $problem = [
            'type' => 'about:blank',
            'title' => Response::$statusTexts[$status] ?? 'Error',
            'status' => $status,
            'detail' => $status < 500 && '' !== $exception->getMessage() ? $exception->getMessage() : 'Wystąpił nieoczekiwany błąd. Spróbuj ponownie później.',
        ];

        // The framework's message names the missing role; the client knows its key by its scopes
        if ($exception instanceof AccessDeniedHttpException) {
            $problem['detail'] = 'Ten klucz API nie ma zakresu potrzebnego do tej operacji.';
        }

        $violations = $exception->getPrevious() instanceof ValidationFailedException ? $exception->getPrevious()->getViolations() : [];
        if (\count($violations) > 0) {
            $problem['detail'] = 'Żądanie zawiera błędne dane.';
            $problem['violations'] = array_map(
                static fn (ConstraintViolationInterface $violation): array => ['field' => $violation->getPropertyPath(), 'message' => (string) $violation->getMessage()],
                iterator_to_array($violations),
            );
        }

        $event->setResponse(self::response($problem, $headers));
    }

    /**
     * A problem response for an error found by the application itself, e.g. missing stock.
     *
     * @param array<string, mixed>  $extra   additional members, e.g. "shortages"
     * @param array<string, string> $headers
     */
    public static function create(int $status, string $detail, array $extra = [], array $headers = []): JsonResponse
    {
        return self::response([
            'type' => 'about:blank',
            'title' => Response::$statusTexts[$status] ?? 'Error',
            'status' => $status,
            'detail' => $detail,
            ...$extra,
        ], $headers);
    }

    public static function isApi(string $path): bool
    {
        return 1 === preg_match('{^/api(?!/doc)(/|$)}', $path);
    }

    /**
     * @param array<string, mixed>  $problem
     * @param array<string, string> $headers
     */
    private static function response(array $problem, array $headers): JsonResponse
    {
        /** @var int $status */
        $status = $problem['status'];

        $response = new JsonResponse($problem, $status, ['Content-Type' => 'application/problem+json', ...$headers]);

        // Polish letters as they are, not as \u017c
        return $response->setEncodingOptions($response->getEncodingOptions() | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
    }
}
