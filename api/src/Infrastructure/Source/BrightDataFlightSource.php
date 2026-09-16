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
use Psr\Log\LoggerInterface;
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
        private ?LoggerInterface $logger = null,
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

        // Als noch best_flights noch other_flights aanwezig is, heeft de bron zijn
        // antwoordvorm gewijzigd (hernoemd veld, foutobject {"error":"…"}, lege payload).
        // Dat is precies het scenario dat eerder stil 0 resultaten gaf zonder enige aanwijzing.
        if (!isset($payload['best_flights']) && !isset($payload['other_flights'])) {
            $this->logger?->warning('Geen vluchtcollectie in payload voor route {from}→{to} ({date}).', [
                'from' => $from->value,
                'to'   => $to->value,
                'date' => $date->format('Y-m-d'),
            ]);

            return [];
        }

        $raw = [
            ...($payload['best_flights'] ?? []),
            ...($payload['other_flights'] ?? []),
        ];

        $itineraries = [];
        $skipped = 0;

        foreach ($raw as $offer) {
            $itinerary = $this->toItinerary($offer);

            if (null !== $itinerary) {
                $itineraries[] = $itinerary;
            } else {
                ++$skipped;
            }
        }

        if ($skipped > 0) {
            $this->logger?->warning('{count} aanbod(en) overgeslagen voor route {from}→{to} ({date}).', [
                'count' => $skipped,
                'from'  => $from->value,
                'to'    => $to->value,
                'date'  => $date->format('Y-m-d'),
            ]);
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

        $origin = $segments[0]->from;
        $destination = $segments[count($segments) - 1]->to;

        // Helsinki is een eindpunt van de vlucht (vertrek of aankomst): de VR-trein HEL–TKU hoort erbij.
        // Let op: dit gaat over het eindpunt van dit ticket, niet over een tussenlandige overstap —
        // een ticket dat via Helsinki overstapt maar ergens anders eindigt krijgt terecht ThroughTicket.
        $trainNeeded = Airport::Hel === $destination || Airport::Hel === $origin;

        // Een onleesbare prijs (leeg, "N/A", locale-notatie "1.234,00") of een negatieve
        // waarde mag de hele zoekopdracht niet slopen; sla dit aanbod stil over.
        // is_numeric() keurt strings met komma als decimaalscheider af, wat precies
        // de Nederlandse locale-notatie uitsluit.
        $rawPrice = $offer['price'];

        if (!is_numeric($rawPrice)) {
            return null;
        }

        $euros = (float) $rawPrice;

        // De bovengrens sluit absurde bedragen uit en vangt tevens INF af
        // (json_decode maakt van 1e999 een INF-float; INF > 1_000_000.0 is waar).
        if (!is_finite($euros) || $euros > 1_000_000.0) {
            return null;
        }

        $cents = (int) round($euros * 100);

        // Een prijs van nul cent of minder is geen bruikbare prijs. De toets staat
        // op centniveau omdat de spookvlucht daar ontstaat: zowel 0, 0.004 als
        // -0.004 worden (int) round(...*100) = 0 cent. Een scraper die een prijs
        // niet kan parsen levert vaker 0 dan een negatieve waarde.
        if ($cents <= 0) {
            return null;
        }

        try {
            $price = Money::fromCents($cents);
        } catch (\InvalidArgumentException) {
            return null;
        }

        return new Itinerary(
            $carrier,
            $segments,
            $price,
            $trainNeeded ? RoutePattern::ViaHelsinkiWithTrain : RoutePattern::ThroughTicket,
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
