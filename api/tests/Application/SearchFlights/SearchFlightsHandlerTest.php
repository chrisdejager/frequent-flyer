<?php

declare(strict_types=1);

namespace App\Tests\Application\SearchFlights;

use App\Application\SearchFlights\SearchFlightsHandler;
use App\Application\SearchFlights\SearchFlightsQuery;
use App\Domain\Flight\Airport;
use App\Domain\Flight\Carrier;
use App\Domain\Flight\Direction;
use App\Domain\Flight\Itinerary;
use App\Domain\Flight\Money;
use App\Domain\Flight\RoutePattern;
use App\Domain\Flight\Segment;
use App\Domain\Pricing\TotalCostCalculator;
use App\Infrastructure\Persistence\NullPriceHistory;
use App\Infrastructure\Source\IgnavFlightSource;
use App\Tests\Double\InMemoryFlightPriceSource;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class SearchFlightsHandlerTest extends TestCase
{
    public function test_it_returns_options_cheapest_first(): void
    {
        $flights = new InMemoryFlightPriceSource();
        $flights->give(Airport::Ams, Airport::Hel, [
            self::viaHel(Carrier::Finnair, 160, '13:00', '16:45'),
        ]);
        $flights->give(Airport::Ams, Airport::Tku, [
            self::throughTicket(Carrier::Sas, 150),
        ]);

        $result = $this->handler($flights)(
            new SearchFlightsQuery(new \DateTimeImmutable('2026-10-29'), Direction::NlToFi, 'default'),
        );

        self::assertCount(2, $result->options);
        self::assertSame(15000, $result->options[0]->cost->total()->cents, 'SAS through ticket: €150');
        self::assertSame(16000, $result->options[1]->cost->total()->cents, 'Finnair to HEL: €160');
    }

    public function test_options_that_break_the_rules_are_dropped(): void
    {
        $flights = new InMemoryFlightPriceSource();
        $flights->give(Airport::Ams, Airport::Hel, [
            self::viaHel(Carrier::Finnair, 134, '13:00', '16:45'),
            self::viaHel(Carrier::Finnair, 118, '18:30', '22:15'),
        ]);

        $result = $this->handler($flights)(
            new SearchFlightsQuery(new \DateTimeImmutable('2026-10-29'), Direction::NlToFi, 'default'),
        );

        self::assertCount(1, $result->options, 'the cheaper evening flight falls away on arrival time');
        self::assertSame(13400, $result->options[0]->itinerary->basePrice->cents);
    }

    public function test_the_return_direction_searches_from_finland(): void
    {
        $flights = new InMemoryFlightPriceSource();
        $flights->give(Airport::Hel, Airport::Ams, []);
        $flights->give(Airport::Tku, Airport::Ams, []);

        $this->handler($flights)(
            new SearchFlightsQuery(new \DateTimeImmutable('2026-10-25'), Direction::FiToNl, 'default'),
        );

        self::assertSame(
            [[Airport::Hel, Airport::Ams, '2026-10-25'], [Airport::Tku, Airport::Ams, '2026-10-25']],
            $flights->calls,
        );
        self::assertSame([2], $flights->batches,
            'both routes in one call, so the source can fetch them concurrently');
    }

    public function test_return_flight_departing_before_noon_is_rejected(): void
    {
        $flights = new InMemoryFlightPriceSource();
        $flights->give(Airport::Hel, Airport::Ams, [
            self::helToAms(Carrier::Finnair, 80, '08:00'), // too early
        ]);
        $flights->give(Airport::Tku, Airport::Ams, []);

        $result = $this->handler($flights)(
            new SearchFlightsQuery(new \DateTimeImmutable('2026-10-25'), Direction::FiToNl, 'default'),
        );

        self::assertCount(0, $result->options,
            'A HEL→AMS flight departing at 08:00 must be rejected (before 12:00).');
    }

    public function test_real_adapter_gives_a_hel_to_ams_flight_the_helsinki_pattern(): void
    {
        // Integration test: the real IgnavFlightSource (with MockHttpClient) runs through
        // the real SearchFlightsHandler for Direction::FiToNl.
        // Goal: covers pattern determination via $helsinkiEndpoint in the adapter — the
        // other handler tests use helpers that hardcode RoutePattern::Helsinki and never
        // touch the adapter.
        // Mutation test: if $helsinkiEndpoint is reduced to destination-only, this direct
        // HEL→AMS flight becomes a ThroughTicket, fails RequiredStopCount and this test fails.
        $helToAmsJson = json_encode([
            'itineraries' => [[
                'price' => ['amount' => 80, 'currency' => 'EUR', 'status' => 'verified'],
                'requires_self_transfer' => false,
                'outbound' => [
                    'segments' => [[
                        'marketing_carrier_code' => 'AY',
                        'flight_number'          => '841',
                        'departure_airport'      => 'HEL',
                        'departure_time_utc'     => '2026-10-25T14:00:00Z',
                        'arrival_airport'        => 'AMS',
                        'arrival_time_utc'       => '2026-10-25T16:45:00Z',
                    ]],
                ],
            ]],
        ], JSON_THROW_ON_ERROR);

        // The handler searches for both HEL→AMS and TKU→AMS for FiToNl.
        // Both calls return the same answer (HEL→AMS). The route check in the adapter
        // automatically skips the HEL→AMS flight on the TKU→AMS search,
        // so we get exactly one result.
        $client = new MockHttpClient(new MockResponse($helToAmsJson));

        $source = new IgnavFlightSource($client, 'test-key');
        $handler = new SearchFlightsHandler(
            $source,
            new TotalCostCalculator(),
            new NullPriceHistory(),
        );

        $result = $handler(new SearchFlightsQuery(new \DateTimeImmutable('2026-10-25'), Direction::FiToNl, 'default'));

        self::assertCount(1, $result->options, 'One valid HEL→AMS Finnair flight expected');
        self::assertSame(RoutePattern::Helsinki, $result->options[0]->itinerary->pattern);
        self::assertSame(8000, $result->options[0]->cost->total()->cents);
    }

    private function handler(InMemoryFlightPriceSource $flights): SearchFlightsHandler
    {
        return new SearchFlightsHandler(
            $flights,
            new TotalCostCalculator(),
            new NullPriceHistory(),
        );
    }

    private static function helToAms(Carrier $carrier, float $euros, string $dep): Itinerary
    {
        return new Itinerary(
            $carrier,
            [new Segment(
                Airport::Hel,
                Airport::Ams,
                new \DateTimeImmutable('2026-10-25 '.$dep),
                new \DateTimeImmutable('2026-10-25 16:45'),
                'AY841',
            )],
            Money::fromEuros($euros),
            RoutePattern::Helsinki,
        );
    }

    private static function viaHel(Carrier $carrier, float $euros, string $dep, string $arr): Itinerary
    {
        return new Itinerary(
            $carrier,
            [new Segment(
                Airport::Ams,
                Airport::Hel,
                new \DateTimeImmutable('2026-10-29 '.$dep),
                new \DateTimeImmutable('2026-10-29 '.$arr),
                'AY842',
            )],
            Money::fromEuros($euros),
            RoutePattern::Helsinki,
        );
    }

    private static function throughTicket(Carrier $carrier, float $euros): Itinerary
    {
        return new Itinerary(
            $carrier,
            [
                new Segment(
                    Airport::Ams,
                    Airport::Arn,
                    new \DateTimeImmutable('2026-10-29 09:00'),
                    new \DateTimeImmutable('2026-10-29 11:00'),
                    'SK552',
                ),
                new Segment(
                    Airport::Arn,
                    Airport::Tku,
                    new \DateTimeImmutable('2026-10-29 12:30'),
                    new \DateTimeImmutable('2026-10-29 14:10'),
                    'SK721',
                ),
            ],
            Money::fromEuros($euros),
            RoutePattern::ThroughTicket,
        );
    }
}
