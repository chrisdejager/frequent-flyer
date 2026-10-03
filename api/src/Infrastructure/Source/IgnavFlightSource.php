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

    /** Landcode waarmee ignav de markt en daarmee de valuta kiest. */
    private const MARKET = 'NL';

    /** De enige valuta die we accepteren; het domein rekent in eurocenten. */
    private const CURRENCY = 'EUR';

    /**
     * Bovengrens voor een vliegticketprijs in euro.
     * Geen ticket op onze routes (AMS–HEL/TKU) komt in de buurt van dit bedrag;
     * een hogere waarde duidt op een bronartefact, niet op een dure vlucht.
     * Vangt tevens INF af: json_decode maakt van 1e999 een INF-float.
     */
    private const MAX_TICKET_PRICE_EUROS = 1_000_000.0;

    /**
     * Volgorde van maatschappijen van duurste naar goedkoopste bagagetoeslag.
     * Bij gemengde legs kiezen we de maatschappij die de hoogste kosten impliceert
     * (conservatief), zodat het getoonde totaal nooit te laag uitvalt.
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
            $this->logger?->warning('ignav HTTP-fout bij ophalen vluchten ({from}→{to}, {date}): {reason}', [
                'from'   => $from->value,
                'to'     => $to->value,
                'date'   => $date->format('Y-m-d'),
                'reason' => $e->getMessage(),
            ]);

            return [];
        } catch (\JsonException $e) {
            $this->logger?->warning('ignav gaf onleesbare JSON voor vluchten ({from}→{to}, {date}): {reason}', [
                'from'   => $from->value,
                'to'     => $to->value,
                'date'   => $date->format('Y-m-d'),
                'reason' => $e->getMessage(),
            ]);

            return [];
        }

        // Onderscheid "geen vluchten gevonden" (een legitiem lege lijst) van "de bron
        // heeft zijn antwoordvorm gewijzigd" (sleutel ontbreekt of is geen array).
        // De oude adapter gooide die twee op één hoop en gaf dan stil 0 resultaten.
        if (!is_array($payload) || !is_array($payload['itineraries'] ?? null)) {
            $this->logger?->warning('Geen itineraries-collectie in payload voor route {from}→{to} ({date}).', [
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
    private function toItinerary(array $offer, Airport $from, Airport $to): ?Itinerary
    {
        // Een self-transfer is geen doorgaand ticket: bagage wordt niet doorgecheckt
        // en bij een gemiste aansluiting is er geen recht. De bagageberekening verderop
        // gaat uit van één ticket en zou hier stil een verkeerd totaal opleveren.
        if (true === ($offer['requires_self_transfer'] ?? false)) {
            return null;
        }

        $legs = $offer['outbound']['segments'] ?? null;

        if (!is_array($legs) || [] === $legs) {
            return null;
        }

        // Verzamel de maatschappijen van álle legs. Carrier::tryFrom kent alleen
        // AY/SK/KL; BT (Air Baltic) en TK (Turkish) uit de echte respons vallen hier af.
        $carriers = [];
        foreach ($legs as $leg) {
            if (!is_array($leg)) {
                return null;
            }

            $code = $leg['marketing_carrier_code'] ?? null;
            $legCarrier = is_string($code) ? Carrier::tryFrom($code) : null;

            if (null === $legCarrier) {
                // Eén maatschappij buiten Finnair/SAS/KLM mag de hele zoekopdracht niet slopen.
                return null;
            }

            $carriers[] = $legCarrier;
        }

        // Bij gemengde legs kiezen we de maatschappij met de hoogste bagagetoeslag.
        $carrier = null;
        foreach (self::CARRIER_PRIORITY as $priority) {
            if (in_array($priority, $carriers, true)) {
                $carrier = $priority;
                break;
            }
        }

        // $carrier kan hier niet null zijn: alle carriers zijn toegestaan en
        // CARRIER_PRIORITY bevat alle toegestane carriers. Maar voor type-safety:
        if (null === $carrier) {
            return null;
        }

        $segments = [];

        foreach ($legs as $leg) {
            $rawFrom = $leg['departure_airport'] ?? null;
            $rawTo = $leg['arrival_airport'] ?? null;

            $legFrom = is_string($rawFrom) ? Airport::tryFrom($rawFrom) : null;
            $legTo = is_string($rawTo) ? Airport::tryFrom($rawTo) : null;

            // Overstappen buiten AMS/HEL/TKU/ARN/CPH vallen hier af — de echte
            // respons bevatte RIX en IST.
            if (null === $legFrom || null === $legTo) {
                return null;
            }

            $departureTime = $leg['departure_time_utc'] ?? null;
            $arrivalTime = $leg['arrival_time_utc'] ?? null;

            // new \DateTimeImmutable('') gooit géén exception maar levert "nu" terug;
            // een lege string moet daarom expliciet vóór de constructor worden afgevangen.
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

            // ignav levert het vluchtnummer zónder maatschappijcode ("1302"), het
            // domein verwacht "AY1302". Een niet-scalaire waarde zou bij een cast
            // stil "Array" opleveren.
            $rawFlightNumber = $leg['flight_number'] ?? null;

            if (!is_string($rawFlightNumber) && !is_int($rawFlightNumber)) {
                return null;
            }

            // Segment gooit een InvalidArgumentException als aankomst niet ná vertrek ligt;
            // ook dat hoort één aanbieding te laten vervallen, niet de hele zoekopdracht.
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

        // Routecontrole: het aanbod moet exact de gevraagde route bedienen.
        // Een vlucht AMS→ARN bij een AMS→TKU-zoekopdracht wordt hier overgeslagen.
        if ($origin !== $from || $destination !== $to) {
            return null;
        }

        // Helsinki is een eindpunt van de vlucht (vertrek of aankomst): de VR-trein
        // HEL–TKU hoort erbij. Let op: dit gaat over het eindpunt van dit ticket, niet
        // over een tussenlandse overstap — een ticket dat via Helsinki overstapt maar
        // ergens anders eindigt krijgt terecht ThroughTicket.
        $trainNeeded = Airport::Hel === $destination || Airport::Hel === $origin;

        $price = $offer['price'] ?? null;

        if (!is_array($price)) {
            return null;
        }

        // Het domein rekent in eurocenten; een andere valuta zou hier stil een
        // verkeerd bedrag opleveren.
        if (self::CURRENCY !== ($price['currency'] ?? null)) {
            return null;
        }

        // price.amount is uitgedrukt in hele euro's (gemeten: 248, 266, 273), niet in
        // centen. is_numeric() keurt strings met komma als decimaalscheider af, wat
        // precies de Nederlandse locale-notatie uitsluit.
        $rawAmount = $price['amount'] ?? null;

        if (!is_numeric($rawAmount)) {
            return null;
        }

        $euros = (float) $rawAmount;

        if ($euros > self::MAX_TICKET_PRICE_EUROS) {
            return null;
        }

        $cents = (int) round($euros * 100);

        // Een prijs van nul cent of minder is geen bruikbare prijs. De toets staat op
        // centniveau omdat de spookvlucht daar ontstaat: zowel 0, 0.004 als -0.004
        // worden (int) round(...*100) = 0 cent.
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
            // Zonder bruikbaar id blijft de optie gewoon staan, alleen zonder Boeken-knop.
            is_string($offer['ignav_id'] ?? null) && '' !== $offer['ignav_id'] ? $offer['ignav_id'] : null,
        );
    }
}
