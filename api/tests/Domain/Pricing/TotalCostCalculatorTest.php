<?php

declare(strict_types=1);

namespace App\Tests\Domain\Pricing;

use App\Domain\Flight\Airport;
use App\Domain\Flight\Carrier;
use App\Domain\Flight\Itinerary;
use App\Domain\Flight\Money;
use App\Domain\Flight\RoutePattern;
use App\Domain\Flight\Segment;
use App\Domain\Pricing\TotalCostCalculator;
use PHPUnit\Framework\TestCase;

final class TotalCostCalculatorTest extends TestCase
{
    public function test_the_total_of_a_helsinki_flight_is_its_ticket_price(): void
    {
        $cost = (new TotalCostCalculator())->calculate(self::viaHel(Carrier::Finnair, 134));

        self::assertSame(13400, $cost->basePrice->cents);
        self::assertSame(13400, $cost->total()->cents);
    }

    public function test_the_total_of_a_through_ticket_is_its_ticket_price(): void
    {
        $cost = (new TotalCostCalculator())->calculate(self::throughTicket(Carrier::Sas, 150));

        self::assertSame(15000, $cost->total()->cents);
    }

    private static function viaHel(Carrier $carrier, float $euros): Itinerary
    {
        return new Itinerary(
            $carrier,
            [new Segment(
                Airport::Ams,
                Airport::Hel,
                new \DateTimeImmutable('2026-10-29 13:00'),
                new \DateTimeImmutable('2026-10-29 16:45'),
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
