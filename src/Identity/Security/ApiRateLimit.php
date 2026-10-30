<?php

namespace App\Identity\Security;

use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Limits how often one API key may call the API ("api" limiter in rate_limiter.yaml).
 *
 * Runs right after the firewall has authenticated the key, so the limit is per client and not per
 * address: two shops behind one proxy do not share a limit. Every API response tells the client
 * how many requests it has left; over the limit it gets 429 and when to come back.
 */
final class ApiRateLimit
{
    private const string REMAINING = '_api_rate_limit_remaining';

    public function __construct(
        private readonly Security $security,
        #[Target('api.limiter')]
        private readonly RateLimiterFactoryInterface $limiter,
    ) {
    }

    // The firewall listens at priority 8
    #[AsEventListener(event: KernelEvents::REQUEST, priority: 7)]
    public function onRequest(RequestEvent $event): void
    {
        $client = $this->security->getUser();
        if (!$event->isMainRequest() || !$client instanceof ApiClient) {
            return;
        }

        $limit = $this->limiter->create($client->getUserIdentifier())->consume();
        $event->getRequest()->attributes->set(self::REMAINING, $limit->getRemainingTokens());

        if (!$limit->isAccepted()) {
            $retryAfter = max(1, $limit->getRetryAfter()->getTimestamp() - time());

            $event->setResponse(new JsonResponse(
                [
                    'type' => 'https://datatracker.ietf.org/doc/html/rfc6585#section-4',
                    'title' => 'Too Many Requests',
                    'status' => Response::HTTP_TOO_MANY_REQUESTS,
                    'detail' => \sprintf('Limit %d zapytań na minutę został wyczerpany. Spróbuj ponownie za %d s.', $limit->getLimit(), $retryAfter),
                ],
                Response::HTTP_TOO_MANY_REQUESTS,
                ['Content-Type' => 'application/problem+json', 'Retry-After' => (string) $retryAfter, 'X-RateLimit-Remaining' => '0'],
            ));
        }
    }

    #[AsEventListener(event: KernelEvents::RESPONSE)]
    public function onResponse(ResponseEvent $event): void
    {
        $remaining = $event->getRequest()->attributes->get(self::REMAINING);
        if (\is_int($remaining) && !$event->getResponse()->headers->has('X-RateLimit-Remaining')) {
            $event->getResponse()->headers->set('X-RateLimit-Remaining', (string) $remaining);
        }
    }
}
