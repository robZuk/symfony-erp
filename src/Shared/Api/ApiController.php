<?php

namespace App\Shared\Api;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Base of the REST API controllers: JSON with Polish letters and slashes as they are
 * ("Żarówka", "ZS/2026/10/0001"), as in the problem responses of ProblemDetails.
 */
abstract class ApiController extends AbstractController
{
    /**
     * @param array<string, string> $headers
     */
    protected function respond(mixed $data, int $status = 200, array $headers = []): JsonResponse
    {
        return $this->json($data, $status, $headers, [
            'json_encode_options' => JsonResponse::DEFAULT_ENCODING_OPTIONS | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES,
        ]);
    }
}
