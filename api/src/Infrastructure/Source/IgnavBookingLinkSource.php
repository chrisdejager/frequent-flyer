<?php

declare(strict_types=1);

namespace App\Infrastructure\Source;

use App\Domain\Booking\BookingLink;
use App\Domain\Booking\BookingLinkUnavailable;
use App\Domain\Port\BookingLinkSource;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class IgnavBookingLinkSource implements BookingLinkSource
{
    private const ENDPOINT = 'https://ignav.com/api/fares/booking-links';
    /** Measured 2026-10-03: carrier itself = "airline", travel agency = "third_party". */
    private const DIRECT_PROVIDER_TYPE = 'airline';

    /** Half an hour: a second click on the same flight then costs no ignav call. */
    private const TTL_SECONDS = 1800;

    /** Stands in the cache for "no direct link", that also saves a call. */
    private const NO_DIRECT_SENTINEL = '__no_direct_link__';

    public function __construct(
        private HttpClientInterface $http,
        private CacheItemPoolInterface $cache,
        private string $apiKey,
        private ?LoggerInterface $logger = null,
    ) {
    }

    public function linkFor(string $bookingRef): BookingLink
    {
        $item = $this->cache->getItem('ignav.booking.'.$bookingRef);

        if ($item->isHit()) {
            $cached = $item->get();

            if (is_array($cached) && is_string($cached['url'] ?? null) && is_string($cached['provider'] ?? null)) {
                return BookingLink::direct($cached['url'], $cached['provider']);
            }

            if (self::NO_DIRECT_SENTINEL === $cached) {
                return BookingLink::noDirectLink();
            }
            // Unrecognized value: just ask again.
        }

        $link = $this->fetch($bookingRef);

        // Only a real response comes here; a failure has already thrown and will
        // thus never be cached.
        $item->set($link->isDirect() ? ['url' => $link->url, 'provider' => $link->provider] : self::NO_DIRECT_SENTINEL);
        $item->expiresAfter(self::TTL_SECONDS);
        $this->cache->save($item);

        return $link;
    }

    private function fetch(string $bookingRef): BookingLink
    {
        $response = $this->http->request('POST', self::ENDPOINT, [
            'headers' => [
                'X-Api-Key: '.$this->apiKey,
                'Content-Type: application/json',
            ],
            'body' => json_encode(['ignav_id' => $bookingRef], JSON_THROW_ON_ERROR),
        ]);

        try {
            $payload = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        } catch (ExceptionInterface|\JsonException $e) {
            // getContent() throws on every non-2xx and on transport errors; an expired
            // ignav_id falls here too (how ignav reports it is not measured).
            $this->logger?->warning('ignav booking-links failed for {ref}: {reason}', [
                'ref' => $bookingRef,
                'reason' => $e->getMessage(),
            ]);

            throw new BookingLinkUnavailable('Booking link could not be retrieved.', 0, $e);
        }

        // An empty list is "no links"; a missing or other form is a
        // changed or broken response — that must not be cached as "no direct link".
        if (!is_array($payload) || !is_array($payload['booking_options'] ?? null)) {
            $this->logger?->warning('ignav booking-links missing booking_options for {ref}.', ['ref' => $bookingRef]);

            throw new BookingLinkUnavailable('Unexpected response from the booking source.');
        }

        return $this->pickDirect($payload);
    }

    /** @param array<string,mixed> $payload */
    private function pickDirect(array $payload): BookingLink
    {
        $cheapest = null;
        $cheapestPrice = \INF;

        foreach ($payload['booking_options'] as $option) {
            if (!is_array($option)) {
                continue;
            }

            $links = $option['links'] ?? null;
            if (!is_array($links)) {
                continue;
            }

            foreach ($links as $link) {
                if (!is_array($link)) {
                    continue;
                }

                if (self::DIRECT_PROVIDER_TYPE !== ($link['provider_type'] ?? null)) {
                    continue;
                }

                $url = $link['url'] ?? null;
                if (!is_string($url) || !str_starts_with($url, 'https://')) {
                    continue;
                }

                $provider = $link['provider_name'] ?? null;
                if (!is_string($provider)) {
                    continue;
                }

                // Without a usable price, a link counts as most expensive, but remains a candidate.
                $amount = is_array($link['price'] ?? null) && is_numeric($link['price']['amount'] ?? null)
                    ? (float) $link['price']['amount']
                    : \INF;

                if (null === $cheapest || $amount < $cheapestPrice) {
                    $cheapest = ['url' => $url, 'provider' => $provider];
                    $cheapestPrice = $amount;
                }
            }
        }

        if (null === $cheapest) {
            return BookingLink::noDirectLink();
        }

        return BookingLink::direct($cheapest['url'], $cheapest['provider']);
    }
}
