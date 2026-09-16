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
use App\Domain\Pricing\BaggagePolicy;
use App\Domain\Pricing\FareUpgradeRates;
use App\Domain\Pricing\TotalCostCalculator;
use App\Infrastructure\Persistence\NullPriceHistory;
use App\Tests\Double\FixedTrainPriceSource;
use App\Tests\Double\InMemoryFlightPriceSource;
use PHPUnit\Framework\TestCase;

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

        $result = $this->handler($flights, new FixedTrainPriceSource(Money::fromEuros(22.50)))(
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

        $result = $this->handler($flights, new FixedTrainPriceSource(Money::fromEuros(22.50)))(
            new SearchFlightsQuery(new \DateTimeImmutable('2026-10-29'), Direction::NlToFi, 'chris'),
        );

        self::assertCount(1, $result->options, 'de goedkopere avondvlucht valt af op aankomsttijd');
        self::assertSame(13400, $result->options[0]->itinerary->basePrice->cents);
    }

    public function test_the_partner_gets_a_higher_total_on_finnair(): void
    {
        $flights = new InMemoryFlightPriceSource();
        $flights->give(Airport::Ams, Airport::Hel, [self::viaHel(Carrier::Finnair, 134, '13:00', '16:45')]);

        $handler = $this->handler($flights, new FixedTrainPriceSource(Money::fromEuros(22.50)));

        $chris = $handler(new SearchFlightsQuery(new \DateTimeImmutable('2026-10-29'), Direction::NlToFi, 'chris'));
        $partner = $handler(new SearchFlightsQuery(new \DateTimeImmutable('2026-10-29'), Direction::NlToFi, 'partner'));

        self::assertSame(15650, $chris->options[0]->cost->total()->cents);
        self::assertSame(19150, $partner->options[0]->cost->total()->cents);
    }

    public function test_the_train_price_is_not_fetched_when_no_helsinki_option_survives(): void
    {
        $flights = new InMemoryFlightPriceSource();
        $flights->give(Airport::Ams, Airport::Tku, [self::throughTicket(Carrier::Sas, 150)]);

        $train = new FixedTrainPriceSource(Money::fromEuros(22.50));

        $this->handler($flights, $train)(
            new SearchFlightsQuery(new \DateTimeImmutable('2026-10-29'), Direction::NlToFi, 'chris'),
        );

        self::assertSame(0, $train->callCount, 'geen HEL-optie, dus geen apify-tegoed verbruiken');
    }

    public function test_the_train_price_is_fetched_only_once(): void
    {
        $flights = new InMemoryFlightPriceSource();
        $flights->give(Airport::Ams, Airport::Hel, [
            self::viaHel(Carrier::Finnair, 134, '13:00', '16:45'),
            self::viaHel(Carrier::Finnair, 145, '14:00', '17:45'),
        ]);

        $train = new FixedTrainPriceSource(Money::fromEuros(22.50));

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

        $this->handler($flights, new FixedTrainPriceSource(Money::zero()))(
            new SearchFlightsQuery(new \DateTimeImmutable('2026-10-25'), Direction::FiToNl, 'partner'),
        );

        self::assertSame(
            [[Airport::Hel, Airport::Ams, '2026-10-25'], [Airport::Tku, Airport::Ams, '2026-10-25']],
            $flights->calls,
        );
    }

    private function handler(InMemoryFlightPriceSource $flights, FixedTrainPriceSource $train): SearchFlightsHandler
    {
        $rates = FareUpgradeRates::fromConfig(['AY' => 35, 'KL' => 40]);

        return new SearchFlightsHandler(
            $flights,
            $train,
            new TotalCostCalculator(new BaggagePolicy($rates), $rates),
            new NullPriceHistory(),
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
