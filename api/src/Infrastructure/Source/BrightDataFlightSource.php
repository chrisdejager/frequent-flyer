<?php

declare(strict_types=1);

namespace App\Infrastructure\Source;

use App\Domain\Flight\Airport;
use App\Domain\Flight\Carrier;
use App\Domain\Flight\Itinerary;
use App\Domain\Flight\Money;
use App\Domain\Flight\RoutePattern;
use App\Domain\Flight\Segment;
use App\Domain\Port\FlightPriceSource;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class BrightDataFlightSource implements FlightPriceSource
{
    private const ENDPOINT = 'https://api.brightdata.com/request';

    /** Google Flights noemt maatschappijen bij naam, wij werken met codes. */
    private const AIRLINES = [
        'Finnair' => Carrier::Finnair,
        'SAS' => Carrier::Sas,
        'Scandinavian Airlines' => Carrier::Sas,
        'KLM' => Carrier::Klm,
    ];

    public function __construct(
        private HttpClientInterface $http,
        private string $apiToken,
        private string $zone,
    ) {
    }

    public function search(Airport $from, Airport $to, \DateTimeImmutable $date): array
    {
        $response = $this->http->request('POST', self::ENDPOINT, [
            'headers' => [
                'Authorization: Bearer '.$this->apiToken,
                'Content-Type: application/json',
            ],
            'json' => [
                'zone' => $this->zone,
                'format' => 'json',
                'url' => $this->googleFlightsUrl($from, $to, $date),
            ],
        ]);

        $payload = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        $raw = [
            ...($payload['best_flights'] ?? []),
            ...($payload['other_flights'] ?? []),
        ];

        $itineraries = [];

        foreach ($raw as $offer) {
            $itinerary = $this->toItinerary($offer);

            if (null !== $itinerary) {
                $itineraries[] = $itinerary;
            }
        }

        return $itineraries;
    }

    /**
     * @param array<string, mixed> $offer
     */
    private function toItinerary(array $offer): ?Itinerary
    {
        $legs = $offer['flights'] ?? [];

        if ([] === $legs || !isset($offer['price'])) {
            return null;
        }

        $carrier = self::AIRLINES[$legs[0]['airline'] ?? ''] ?? null;

        if (null === $carrier) {
            // Een maatschappij buiten Finnair/SAS/KLM. Overslaan in plaats van
            // afbreken: één onbekende aanbieder mag de hele zoekopdracht niet slopen.
            return null;
        }

        $segments = [];

        foreach ($legs as $leg) {
            $from = Airport::tryFrom($leg['departure_airport']['id'] ?? '');
            $to = Airport::tryFrom($leg['arrival_airport']['id'] ?? '');

            if (null === $from || null === $to) {
                return null;
            }

            // Een onleesbaar tijdstip (leeg, "N/A", ontbrekende sleutel) mag de hele
            // zoekopdracht niet slopen; sla dit aanbod stil over.
            // Let op: new \DateTimeImmutable('') gooit géén exception maar levert "nu" terug —
            // een lege string moet daarom expliciet worden afgevangen vóór de constructor.
            $departureTime = $leg['departure_airport']['time'] ?? '';
            $arrivalTime = $leg['arrival_airport']['time'] ?? '';

            if ('' === $departureTime || '' === $arrivalTime) {
                return null;
            }

            try {
                $departAt = new \DateTimeImmutable($departureTime);
                $arriveAt = new \DateTimeImmutable($arrivalTime);
            } catch (\Exception) {
                return null;
            }

            // Segment gooit een InvalidArgumentException als aankomst niet ná vertrek ligt;
            // ook dat hoort één aanbieding te laten vervallen, niet de hele zoekopdracht.
            try {
                $segments[] = new Segment(
                    $from,
                    $to,
                    $departAt,
                    $arriveAt,
                    str_replace(' ', '', (string) ($leg['flight_number'] ?? '')),
                );
            } catch (\InvalidArgumentException) {
                return null;
            }
        }

        if (count($segments) > 2) {
            return null;
        }

        $origin      = $segments[0]->from;
        $destination = $segments[count($segments) - 1]->to;

        // Helsinki als eindpunt (heen of terug) betekent dat de VR-trein HEL–TKU erbij hoort.
        $viaHelsinki = Airport::Hel === $destination || Airport::Hel === $origin;

        return new Itinerary(
            $carrier,
            $segments,
            Money::fromEuros((float) $offer['price']),
            $viaHelsinki ? RoutePattern::ViaHelsinkiWithTrain : RoutePattern::ThroughTicket,
        );
    }

    private function googleFlightsUrl(Airport $from, Airport $to, \DateTimeImmutable $date): string
    {
        return sprintf(
            'https://www.google.com/travel/flights?q=Flights%%20to%%20%s%%20from%%20%s%%20on%%20%s&curr=EUR&hl=nl',
            $to->value,
            $from->value,
            $date->format('Y-m-d'),
        );
    }
}
