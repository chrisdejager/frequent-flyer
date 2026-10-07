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
use App\Tests\Double\FixedTrainPriceSource;
use App\Tests\Double\InMemoryFlightPriceSource;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class SearchFlightsHandlerTest extends TestCase
{
    public function test_it_returns_options_cheapest_first_including_the_train(): void
    {
        $flights = new InMemoryFlightPriceSource();
        $flights->give(Airport::Ams, Airport::Hel, [
            self::viaHel(Carrier::Finnair, 134, '13:00', '16:45'),
        ]);
        $flights->give(Airport::Ams, Airport::Tku, [
            self::throughTicket(Carrier::Sas, 150),
        ]);

        $result = $this->handler($flights, FixedTrainPriceSource::withPrice(Money::fromEuros(22.50)))(
            new SearchFlightsQuery(new \DateTimeImmutable('2026-10-29'), Direction::NlToFi, 'chris'),
        );

        self::assertCount(2, $result->options);
        self::assertSame(15000, $result->options[0]->cost->total()->cents, 'SAS doorgaand: €150');
        self::assertSame(15650, $result->options[1]->cost->total()->cents, 'Finnair €134 + trein €22,50');
    }

    public function test_options_that_break_the_rules_are_dropped(): void
    {
        $flights = new InMemoryFlightPriceSource();
        $flights->give(Airport::Ams, Airport::Hel, [
            self::viaHel(Carrier::Finnair, 134, '13:00', '16:45'),
            self::viaHel(Carrier::Finnair, 118, '18:30', '22:15'),
        ]);

        $result = $this->handler($flights, FixedTrainPriceSource::withPrice(Money::fromEuros(22.50)))(
            new SearchFlightsQuery(new \DateTimeImmutable('2026-10-29'), Direction::NlToFi, 'chris'),
        );

        self::assertCount(1, $result->options, 'the cheaper evening flight falls away on arrival time');
        self::assertSame(13400, $result->options[0]->itinerary->basePrice->cents);
    }

    public function test_chris_and_partner_get_the_same_total_on_finnair(): void
    {
        // Baggage costs are out (simplification): the traveler must not
        // influence it more.
        $flights = new InMemoryFlightPriceSource();
        $flights->give(Airport::Ams, Airport::Hel, [self::viaHel(Carrier::Finnair, 134, '13:00', '16:45')]);

        $handler = $this->handler($flights, FixedTrainPriceSource::withPrice(Money::fromEuros(22.50)));

        $chris = $handler(new SearchFlightsQuery(new \DateTimeImmutable('2026-10-29'), Direction::NlToFi, 'chris'));
        $partner = $handler(new SearchFlightsQuery(new \DateTimeImmutable('2026-10-29'), Direction::NlToFi, 'partner'));

        self::assertSame(15650, $chris->options[0]->cost->total()->cents);
        self::assertSame(15650, $partner->options[0]->cost->total()->cents);
    }

    public function test_the_train_price_is_not_fetched_when_no_helsinki_option_survives(): void
    {
        $flights = new InMemoryFlightPriceSource();
        $flights->give(Airport::Ams, Airport::Tku, [self::throughTicket(Carrier::Sas, 150)]);

        $train = FixedTrainPriceSource::withPrice(Money::fromEuros(22.50));

        $this->handler($flights, $train)(
            new SearchFlightsQuery(new \DateTimeImmutable('2026-10-29'), Direction::NlToFi, 'chris'),
        );

        self::assertSame(0, $train->callCount, 'no HEL option, so no apify credits used');
    }

    public function test_the_train_price_is_not_fetched_when_the_only_helsinki_option_is_filtered_out(): void
    {
        $flights = new InMemoryFlightPriceSource();
        $flights->give(Airport::Ams, Airport::Hel, [
            self::viaHel(Carrier::Finnair, 118, '18:30', '22:15'), // arrival 22:15 fails on time window
        ]);
        $flights->give(Airport::Ams, Airport::Tku, [
            self::throughTicket(Carrier::Sas, 150),
        ]);

        $train = FixedTrainPriceSource::withPrice(Money::fromEuros(22.50));

        $result = $this->handler($flights, $train)(
            new SearchFlightsQuery(new \DateTimeImmutable('2026-10-29'), Direction::NlToFi, 'chris'),
        );

        self::assertSame(0, $train->callCount, 'the HEL flight fell away on the travel rules, so no train price needed');
        self::assertCount(1, $result->options, 'only the through ticket remains — the zero call does not come from an empty result');
    }

    public function test_the_train_price_is_fetched_only_once(): void
    {
        $flights = new InMemoryFlightPriceSource();
        $flights->give(Airport::Ams, Airport::Hel, [
            self::viaHel(Carrier::Finnair, 134, '13:00', '16:45'),
            self::viaHel(Carrier::Finnair, 145, '14:00', '17:45'),
        ]);

        $train = FixedTrainPriceSource::withPrice(Money::fromEuros(22.50));

        $this->handler($flights, $train)(
            new SearchFlightsQuery(new \DateTimeImmutable('2026-10-29'), Direction::NlToFi, 'chris'),
        );

        self::assertSame(1, $train->callCount);
    }

    public function test_the_return_direction_searches_from_finland(): void
    {
        $flights = new InMemoryFlightPriceSource();
        $flights->give(Airport::Hel, Airport::Ams, []);
        $flights->give(Airport::Tku, Airport::Ams, []);

        $this->handler($flights, FixedTrainPriceSource::withPrice(Money::zero()))(
            new SearchFlightsQuery(new \DateTimeImmutable('2026-10-25'), Direction::FiToNl, 'partner'),
        );

        self::assertSame(
            [[Airport::Hel, Airport::Ams, '2026-10-25'], [Airport::Tku, Airport::Ams, '2026-10-25']],
            $flights->calls,
        );
    }

    public function test_return_flight_via_helsinki_includes_train_cost(): void
    {
        $flights = new InMemoryFlightPriceSource();
        $flights->give(Airport::Hel, Airport::Ams, [
            self::helToAms(Carrier::Finnair, 80, '14:00'),
        ]);
        $flights->give(Airport::Tku, Airport::Ams, []);

        $train = FixedTrainPriceSource::withPrice(Money::fromEuros(22.50));

        $result = $this->handler($flights, $train)(
            new SearchFlightsQuery(new \DateTimeImmutable('2026-10-25'), Direction::FiToNl, 'chris'),
        );

        self::assertCount(1, $result->options);
        self::assertSame(1, $train->callCount, 'train price must be fetched for HEL→AMS');
        self::assertSame(10250, $result->options[0]->cost->total()->cents, 'base €80 + train €22.50 = €102.50');
    }

    public function test_return_flight_departing_before_noon_is_rejected(): void
    {
        $flights = new InMemoryFlightPriceSource();
        $flights->give(Airport::Hel, Airport::Ams, [
            self::helToAms(Carrier::Finnair, 80, '08:00'), // too early
        ]);
        $flights->give(Airport::Tku, Airport::Ams, []);

        $result = $this->handler($flights, FixedTrainPriceSource::withPrice(Money::fromEuros(22.50)))(
            new SearchFlightsQuery(new \DateTimeImmutable('2026-10-25'), Direction::FiToNl, 'chris'),
        );

        self::assertCount(0, $result->options,
            'A HEL→AMS flight departing at 08:00 must be rejected (before 12:00).');
    }

    public function test_real_adapter_hel_to_ams_finnair_gets_train_pattern_and_correct_total(): void
    {
        // Integration test: the real IgnavFlightSource (with MockHttpClient) runs through
        // the real SearchFlightsHandler for Direction::FiToNl.
        // Goal: covers pattern determination via $trainNeeded in the adapter — the two existing
        // handler tests (test_return_flight_via_helsinki_includes_train_cost and
        // test_return_flight_departing_before_noon_is_rejected) use the helToAms() helper
        // which hardcodes RoutePattern::ViaHelsinkiWithTrain and never touch the adapter.
        // Mutation test: if $trainNeeded = Airport::Hel === $destination || Airport::Hel === $origin
        // is reversed to destination-only, only this test fails; the two existing
        // handler tests remain green.
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
            FixedTrainPriceSource::withPrice(Money::fromEuros(22.50)),
            new TotalCostCalculator(trainCostEnabled: true),
            new NullPriceHistory(),
        );

        $result = $handler(new SearchFlightsQuery(new \DateTimeImmutable('2026-10-25'), Direction::FiToNl, 'chris'));

        self::assertCount(1, $result->options, 'One valid HEL→AMS Finnair flight expected');

        self::assertSame(10250, $result->options[0]->cost->total()->cents,
            'base €80 + train €22.50 = €102.50; adapter must recognize HEL as source as ViaHelsinkiWithTrain');
    }

    public function test_unknown_train_price_option_goes_last_without_estimate(): void
    {
        // New approach (finding 1): no estimate, no assumption.
        // Options with unknown train price are PUT AT THE END, in arrival order.
        // SAS through €150 (trainCostKnown=true) comes before Finnair via HEL €140
        // (trainCostKnown=false, unknown train), even though €140 < €150.
        $flights = new InMemoryFlightPriceSource();
        $flights->give(Airport::Ams, Airport::Hel, [
            self::viaHel(Carrier::Finnair, 140, '13:00', '16:45'),
        ]);
        $flights->give(Airport::Ams, Airport::Tku, [
            self::throughTicket(Carrier::Sas, 150),
        ]);

        $result = $this->handler($flights, FixedTrainPriceSource::withUnknownPrice())(
            new SearchFlightsQuery(new \DateTimeImmutable('2026-10-29'), Direction::NlToFi, 'chris'),
        );

        self::assertCount(2, $result->options);
        // SAS comes first: trainCostKnown=true, total €150
        self::assertSame(15000, $result->options[0]->cost->total()->cents,
            'SAS through comes first: trainCostKnown=true, €150');
        // Finnair comes second: trainCostKnown=false — no estimate added
        self::assertSame(14000, $result->options[1]->cost->total()->cents,
            'Finnair shown as €140 — no estimate, at the end because train price unknown');
        self::assertFalse($result->options[1]->cost->trainCostKnown,
            'Finnair option has trainCostKnown=false');
    }

    private function handler(InMemoryFlightPriceSource $flights, FixedTrainPriceSource $train): SearchFlightsHandler
    {
        return new SearchFlightsHandler(
            $flights,
            $train,
            new TotalCostCalculator(trainCostEnabled: true),
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
            RoutePattern::ViaHelsinkiWithTrain,
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
            RoutePattern::ViaHelsinkiWithTrain,
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
