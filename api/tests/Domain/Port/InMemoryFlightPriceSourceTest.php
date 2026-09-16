<?php

declare(strict_types=1);

namespace App\Tests\Domain\Port;

use App\Domain\Flight\Airport;
use App\Domain\Flight\Carrier;
use App\Domain\Flight\Itinerary;
use App\Domain\Flight\Money;
use App\Domain\Flight\RoutePattern;
use App\Domain\Flight\Segment;
use App\Tests\Double\FixedTrainPriceSource;
use App\Tests\Double\InMemoryFlightPriceSource;
use PHPUnit\Framework\TestCase;

final class InMemoryFlightPriceSourceTest extends TestCase
{
    public function test_it_returns_what_was_given_for_that_route(): void
    {
        $source = new InMemoryFlightPriceSource();
        $source->give(Airport::Ams, Airport::Hel, [self::anItinerary()]);

        $found = $source->search(Airport::Ams, Airport::Hel, new \DateTimeImmutable('2026-10-29'));

        self::assertCount(1, $found);
        self::assertSame([[Airport::Ams, Airport::Hel, '2026-10-29']], $source->calls);
    }

    public function test_an_unknown_route_yields_nothing(): void
    {
        $source = new InMemoryFlightPriceSource();

        self::assertSame([], $source->search(Airport::Ams, Airport::Tku, new \DateTimeImmutable('2026-10-29')));
    }

    public function test_the_train_double_counts_its_calls(): void
    {
        $train = new FixedTrainPriceSource(Money::fromEuros(22.50));

        $train->priceFor(new \DateTimeImmutable('2026-10-29'));

        self::assertSame(1, $train->callCount);
        self::assertSame(2250, $train->priceFor(new \DateTimeImmutable('2026-10-29'))->cents);
    }

    private static function anItinerary(): Itinerary
    {
        return new Itinerary(
            Carrier::Finnair,
            [new Segment(
                Airport::Ams,
                Airport::Hel,
                new \DateTimeImmutable('2026-10-29 13:00'),
                new \DateTimeImmutable('2026-10-29 16:45'),
                'AY842',
            )],
            Money::fromEuros(134),
            RoutePattern::ViaHelsinkiWithTrain,
        );
    }
}
