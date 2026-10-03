<?php

declare(strict_types=1);

namespace App\Infrastructure\Source;

use App\Domain\Booking\BookingLink;
use App\Domain\Booking\BookingLinkUnavailable;
use App\Domain\Port\BookingLinkSource;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class IgnavBookingLinkSource implements BookingLinkSource
{
    private const ENDPOINT = 'https://ignav.com/api/fares/booking-links';
    /** Gemeten 2026-10-03: maatschappij zelf = "airline", reisbureau = "third_party". */
    private const DIRECT_PROVIDER_TYPE = 'airline';

    public function __construct(
        private HttpClientInterface $http,
        private string $apiKey,
        private ?LoggerInterface $logger = null,
    ) {
    }

    public function linkFor(string $bookingRef): BookingLink
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
            // getContent() gooit bij elke niet-2xx en bij transportfouten; een verlopen
            // ignav_id valt hier dus ook onder (hoe ignav dat meldt is niet gemeten).
            $this->logger?->warning('ignav booking-links mislukt voor {ref}: {reason}', [
                'ref' => $bookingRef,
                'reason' => $e->getMessage(),
            ]);

            throw new BookingLinkUnavailable('Boekingslink kon niet worden opgehaald.', 0, $e);
        }

        // Een lege lijst is "geen links"; een ontbrekende of andere vorm is een
        // gewijzigde of kapotte respons — dat mag niet als "geen directe link" gecachet worden.
        if (!is_array($payload) || !is_array($payload['booking_options'] ?? null)) {
            $this->logger?->warning('ignav booking-links zonder booking_options voor {ref}.', ['ref' => $bookingRef]);

            throw new BookingLinkUnavailable('Onverwacht antwoord van de boekingsbron.');
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

                // Zonder bruikbare prijs telt een link als duurste, maar blijft kandidaat.
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
