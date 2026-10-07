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

final readonly class IgnavFlightSource implements FlightPriceSource
{
    private const ENDPOINT = 'https://ignav.com/api/fares/one-way';

    /** Country code by which ignav chooses the market and thus the currency. */
    private const MARKET = 'NL';

    /** The only currency we accept; the domain calculates in euro cents. */
    private const CURRENCY = 'EUR';

    /**
     * Upper limit for a flight ticket price in euros.
     * No ticket on our routes (AMS–HEL/TKU) comes close to this amount;
     * a higher value indicates a source artifact, not an expensive flight.
     * Also catches INF: json_decode converts 1e999 to an INF float.
     */
    private const MAX_TICKET_PRICE_EUROS = 1_000_000.0;

    /**
     * Order of airlines from most to least expensive baggage surcharge.
     * With mixed legs, we choose the airline that implies the highest cost
     * (conservative), so the displayed total never falls too low.
     */
    private const CARRIER_PRIORITY = [
        Carrier::Klm,
        Carrier::Finnair,
        Carrier::Sas,
    ];

    public function __construct(
        private HttpClientInterface $http,
        private string $apiKey,
        private ?LoggerInterface $logger = null,
    ) {
    }

    public function search(Airport $from, Airport $to, \DateTimeImmutable $date): array
    {
        try {
            $response = $this->http->request('POST', self::ENDPOINT, [
                'headers' => [
                    'X-Api-Key: '.$this->apiKey,
                    'Content-Type: application/json',
                ],
                'json' => [
                    'origin' => $from->value,
                    'destination' => $to->value,
                    'departure_date' => $date->format('Y-m-d'),
                    'market' => self::MARKET,
                ],
            ]);

            $payload = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Symfony\Contracts\HttpClient\Exception\ExceptionInterface $e) {
            $this->logger?->warning('ignav HTTP error fetching flights ({from}→{to}, {date}): {reason}', [
                'from'   => $from->value,
                'to'     => $to->value,
                'date'   => $date->format('Y-m-d'),
                'reason' => $e->getMessage(),
            ]);

            return [];
        } catch (\JsonException $e) {
            $this->logger?->warning('ignav returned unreadable JSON for flights ({from}→{to}, {date}): {reason}', [
                'from'   => $from->value,
                'to'     => $to->value,
                'date'   => $date->format('Y-m-d'),
                'reason' => $e->getMessage(),
            ]);

            return [];
        }

        // Distinguish "no flights found" (a legitimate empty list) from "the source
        // changed its response format" (key missing or not an array).
        // The old adapter mixed the two and silently returned 0 results.
        if (!is_array($payload) || !is_array($payload['itineraries'] ?? null)) {
            $this->logger?->warning('No itineraries collection in payload for route {from}→{to} ({date}).', [
                'from' => $from->value,
                'to'   => $to->value,
                'date' => $date->format('Y-m-d'),
            ]);

            return [];
        }

        $itineraries = [];
        $skipped = 0;

        foreach ($payload['itineraries'] as $offer) {
            if (!is_array($offer)) {
                ++$skipped;
                continue;
            }

            $itinerary = $this->toItinerary($offer, $from, $to);

            if (null !== $itinerary) {
                $itineraries[] = $itinerary;
            } else {
                ++$skipped;
            }
        }

        if ($skipped > 0) {
            $this->logger?->warning('{count} offer(s) skipped for route {from}→{to} ({date}).', [
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
    private function toItinerary(array $offer, Airport $from, Airport $to): ?Itinerary
    {
        // A self-transfer is not a through ticket: baggage is not checked through
        // and there is no compensation for a missed connection. The baggage calculation below
        // assumes one ticket and would silently produce a wrong total here.
        if (true === ($offer['requires_self_transfer'] ?? false)) {
            return null;
        }

        $legs = $offer['outbound']['segments'] ?? null;

        if (!is_array($legs) || [] === $legs) {
            return null;
        }

        // Collect airlines from all legs. Carrier::tryFrom only knows
        // AY/SK/KL; BT (Air Baltic) and TK (Turkish) from the real response fall away here.
        $carriers = [];
        foreach ($legs as $leg) {
            if (!is_array($leg)) {
                return null;
            }

            $code = $leg['marketing_carrier_code'] ?? null;
            $legCarrier = is_string($code) ? Carrier::tryFrom($code) : null;

            if (null === $legCarrier) {
                // One airline outside Finnair/SAS/KLM must not break the entire search.
                return null;
            }

            $carriers[] = $legCarrier;
        }

        // With mixed legs, we choose the airline with the highest baggage surcharge.
        $carrier = null;
        foreach (self::CARRIER_PRIORITY as $priority) {
            if (in_array($priority, $carriers, true)) {
                $carrier = $priority;
                break;
            }
        }

        // $carrier cannot be null here: all carriers are allowed and
        // CARRIER_PRIORITY contains all allowed carriers. But for type safety:
        if (null === $carrier) {
            return null;
        }

        $segments = [];

        foreach ($legs as $leg) {
            $rawFrom = $leg['departure_airport'] ?? null;
            $rawTo = $leg['arrival_airport'] ?? null;

            $legFrom = is_string($rawFrom) ? Airport::tryFrom($rawFrom) : null;
            $legTo = is_string($rawTo) ? Airport::tryFrom($rawTo) : null;

            // Connections outside AMS/HEL/TKU/ARN/CPH fall away here — the real
            // response contained RIX and IST.
            if (null === $legFrom || null === $legTo) {
                return null;
            }

            $departureTime = $leg['departure_time_utc'] ?? null;
            $arrivalTime = $leg['arrival_time_utc'] ?? null;

            // new \DateTimeImmutable('') throws no exception but returns "now";
            // an empty string must therefore be explicitly caught before the constructor.
            if (!is_string($departureTime) || '' === $departureTime
                || !is_string($arrivalTime) || '' === $arrivalTime) {
                return null;
            }

            try {
                $departAt = new \DateTimeImmutable($departureTime);
                $arriveAt = new \DateTimeImmutable($arrivalTime);
            } catch (\Exception) {
                return null;
            }

            // ignav provides the flight number without airline code ("1302"), the
            // domain expects "AY1302". A non-scalar value would silently
            // produce "Array" when cast.
            $rawFlightNumber = $leg['flight_number'] ?? null;

            if (!is_string($rawFlightNumber) && !is_int($rawFlightNumber)) {
                return null;
            }

            // Segment throws InvalidArgumentException if arrival is not after departure;
            // that too should cause one offer to be dropped, not the entire search.
            try {
                $segments[] = new Segment(
                    $legFrom,
                    $legTo,
                    $departAt,
                    $arriveAt,
                    $leg['marketing_carrier_code'].str_replace(' ', '', (string) $rawFlightNumber),
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

        // Route validation: the offer must exactly serve the requested route.
        // A flight AMS→ARN on an AMS→TKU search is skipped here.
        if ($origin !== $from || $destination !== $to) {
            return null;
        }

        // Helsinki is an endpoint of the flight (departure or arrival): the VR train
        // HEL–TKU belongs with it. Note: this is about the endpoint of this ticket, not
        // about an intermediate connection — a ticket that connects via Helsinki but
        // ends elsewhere correctly gets ThroughTicket.
        $trainNeeded = Airport::Hel === $destination || Airport::Hel === $origin;

        $price = $offer['price'] ?? null;

        if (!is_array($price)) {
            return null;
        }

        // The domain calculates in euro cents; a different currency would silently
        // produce a wrong amount here.
        if (self::CURRENCY !== ($price['currency'] ?? null)) {
            return null;
        }

        // price.amount is expressed in whole euros (measured: 248, 266, 273), not in
        // cents. is_numeric() rejects strings with comma as decimal separator, which
        // exactly excludes Dutch locale notation.
        $rawAmount = $price['amount'] ?? null;

        if (!is_numeric($rawAmount)) {
            return null;
        }

        $euros = (float) $rawAmount;

        if ($euros > self::MAX_TICKET_PRICE_EUROS) {
            return null;
        }

        $cents = (int) round($euros * 100);

        // A price of zero cents or less is not a usable price. The check is at the
        // cent level because the ghost flight arises there: both 0, 0.004 and -0.004
        // become (int) round(...*100) = 0 cents.
        if ($cents <= 0) {
            return null;
        }

        try {
            $priceMoney = Money::fromCents($cents);
        } catch (\InvalidArgumentException) {
            return null;
        }

        return new Itinerary(
            $carrier,
            $segments,
            $priceMoney,
            $trainNeeded ? RoutePattern::ViaHelsinkiWithTrain : RoutePattern::ThroughTicket,
            // Without a usable id the option remains as is, just without a Book button.
            is_string($offer['ignav_id'] ?? null) && '' !== $offer['ignav_id'] ? $offer['ignav_id'] : null,
        );
    }
}
