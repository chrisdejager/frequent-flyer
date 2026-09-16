<?php

declare(strict_types=1);

namespace App\Tests\Domain\Flight;

use App\Domain\Flight\Airport;
use App\Domain\Flight\Carrier;
use App\Domain\Flight\Itinerary;
use App\Domain\Flight\Money;
use App\Domain\Flight\RoutePattern;
use App\Domain\Flight\Segment;
use PHPUnit\Framework\TestCase;

final class ItineraryTest extends TestCase
{
    public function test_direct_flight_has_no_stops(): void
    {
        $it = new Itinerary(
            Carrier::Finnair,
            [self::segment(Airport::Ams, Airport::Hel, '2026-10-29 13:00', '2026-10-29 16:45', 'AY842')],
            Money::fromEuros(134),
            RoutePattern::ViaHelsinkiWithTrain,
        );

        self::assertSame(0, $it->stopCount());
        self::assertNull($it->connectionMinutes());
        self::assertNull($it->connectsVia());
        self::assertSame(Airport::Ams, $it->origin());
        self::assertSame(Airport::Hel, $it->destination());
    }

    public function test_one_stop_exposes_connection_details(): void
    {
        $it = new Itinerary(
            Carrier::Sas,
            [
                self::segment(Airport::Ams, Airport::Arn, '2026-10-29 09:00', '2026-10-29 11:00', 'SK552'),
                self::segment(Airport::Arn, Airport::Tku, '2026-10-29 12:30', '2026-10-29 14:10', 'SK721'),
            ],
            Money::fromEuros(150),
            RoutePattern::ThroughTicket,
        );

        self::assertSame(1, $it->stopCount());
        self::assertSame(90, $it->connectionMinutes());
        self::assertSame(Airport::Arn, $it->connectsVia());
        self::assertSame(Airport::Ams, $it->origin());
        self::assertSame(Airport::Tku, $it->destination());
    }

    public function test_departure_and_arrival_span_the_whole_journey(): void
    {
        $it = new Itinerary(
            Carrier::Sas,
            [
                self::segment(Airport::Ams, Airport::Arn, '2026-10-29 09:00', '2026-10-29 11:00', 'SK552'),
                self::segment(Airport::Arn, Airport::Tku, '2026-10-29 12:30', '2026-10-29 14:10', 'SK721'),
            ],
            Money::fromEuros(150),
            RoutePattern::ThroughTicket,
        );

        self::assertSame('2026-10-29 09:00', $it->departAt()->format('Y-m-d H:i'));
        self::assertSame('2026-10-29 14:10', $it->arriveAt()->format('Y-m-d H:i'));
    }

    public function test_an_itinerary_without_segments_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Itinerary(Carrier::Klm, [], Money::fromEuros(100), RoutePattern::ThroughTicket);
    }

    public function test_more_than_one_connection_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Itinerary(
            Carrier::Klm,
            [
                self::segment(Airport::Ams, Airport::Cph, '2026-10-29 07:00', '2026-10-29 08:20', 'KL1'),
                self::segment(Airport::Cph, Airport::Arn, '2026-10-29 09:00', '2026-10-29 10:00', 'KL2'),
                self::segment(Airport::Arn, Airport::Tku, '2026-10-29 11:00', '2026-10-29 12:30', 'KL3'),
            ],
            Money::fromEuros(100),
            RoutePattern::ThroughTicket,
        );
    }

    private static function segment(Airport $from, Airport $to, string $dep, string $arr, string $nr): Segment
    {
        return new Segment(
            $from,
            $to,
            new \DateTimeImmutable($dep),
            new \DateTimeImmutable($arr),
            $nr,
        );
    }
}
