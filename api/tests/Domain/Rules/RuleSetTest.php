<?php

declare(strict_types=1);

namespace App\Tests\Domain\Rules;

use App\Domain\Flight\Airport;
use App\Domain\Flight\Carrier;
use App\Domain\Flight\Direction;
use App\Domain\Flight\Itinerary;
use App\Domain\Flight\Money;
use App\Domain\Flight\RoutePattern;
use App\Domain\Flight\Segment;
use App\Domain\Rules\RuleSet;
use PHPUnit\Framework\TestCase;

final class RuleSetTest extends TestCase
{
    public function test_a_midday_finnair_flight_arriving_before_21_is_accepted(): void
    {
        $it = self::viaHel('2026-10-29 13:00', '2026-10-29 16:45', Carrier::Finnair);

        self::assertNull(RuleSet::standard(Direction::NlToFi)->rejectionReason($it));
    }

    public function test_arriving_in_helsinki_after_21_is_rejected(): void
    {
        $it = self::viaHel('2026-10-29 18:30', '2026-10-29 22:15', Carrier::Finnair);

        self::assertSame(
            'Arrival in Helsinki after 21:00 — train to Turku no longer reachable.',
            RuleSet::standard(Direction::NlToFi)->rejectionReason($it),
        );
    }

    public function test_arriving_in_helsinki_at_exactly_21_00_is_accepted(): void
    {
        $it = self::viaHel('2026-10-29 18:30', '2026-10-29 21:00', Carrier::Finnair);

        self::assertNull(RuleSet::standard(Direction::NlToFi)->rejectionReason($it));
    }

    public function test_departing_helsinki_before_noon_is_rejected(): void
    {
        $it = new Itinerary(
            Carrier::Finnair,
            [self::segment(Airport::Hel, Airport::Ams, '2026-10-25 08:00', '2026-10-25 09:50', 'AY841')],
            Money::fromEuros(80),
            RoutePattern::ViaHelsinkiWithTrain,
        );

        self::assertSame(
            'Departure from Helsinki before 12:00.',
            RuleSet::standard(Direction::FiToNl)->rejectionReason($it),
        );
    }

    public function test_departing_helsinki_at_exactly_12_00_is_accepted(): void
    {
        $it = new Itinerary(
            Carrier::Finnair,
            [self::segment(Airport::Hel, Airport::Ams, '2026-10-25 12:00', '2026-10-25 13:50', 'AY841')],
            Money::fromEuros(80),
            RoutePattern::ViaHelsinkiWithTrain,
        );

        self::assertNull(RuleSet::standard(Direction::FiToNl)->rejectionReason($it));
    }

    public function test_a_through_ticket_is_not_judged_on_helsinki_times(): void
    {
        $it = new Itinerary(
            Carrier::Sas,
            [
                self::segment(Airport::Ams, Airport::Arn, '2026-10-29 17:00', '2026-10-29 19:00', 'SK552'),
                self::segment(Airport::Arn, Airport::Tku, '2026-10-29 20:00', '2026-10-29 21:40', 'SK721'),
            ],
            Money::fromEuros(150),
            RoutePattern::ThroughTicket,
        );

        self::assertNull(RuleSet::standard(Direction::NlToFi)->rejectionReason($it));
    }

    public function test_a_connection_longer_than_two_hours_is_rejected(): void
    {
        $it = new Itinerary(
            Carrier::Sas,
            [
                self::segment(Airport::Ams, Airport::Arn, '2026-10-29 09:00', '2026-10-29 11:00', 'SK552'),
                self::segment(Airport::Arn, Airport::Tku, '2026-10-29 14:00', '2026-10-29 15:40', 'SK721'),
            ],
            Money::fromEuros(150),
            RoutePattern::ThroughTicket,
        );

        self::assertSame(
            'Connection time longer than 120 minutes.',
            RuleSet::standard(Direction::NlToFi)->rejectionReason($it),
        );
    }

    public function test_an_unwanted_connection_airport_is_rejected(): void
    {
        $it = new Itinerary(
            Carrier::Klm,
            [
                self::segment(Airport::Ams, Airport::Hel, '2026-10-29 09:00', '2026-10-29 12:40', 'KL1'),
                self::segment(Airport::Hel, Airport::Tku, '2026-10-29 13:30', '2026-10-29 14:20', 'KL2'),
            ],
            Money::fromEuros(200),
            RoutePattern::ThroughTicket,
        );

        self::assertSame(
            'Connection via HEL is not considered for a through ticket.',
            RuleSet::standard(Direction::NlToFi)->rejectionReason($it),
        );
    }

    public function test_a_via_helsinki_option_with_a_stop_is_rejected(): void
    {
        // Only direct flights for HEL<>AMS.
        $it = new Itinerary(
            Carrier::Finnair,
            [
                self::segment(Airport::Ams, Airport::Cph, '2026-10-29 09:00', '2026-10-29 10:30', 'AY1'),
                self::segment(Airport::Cph, Airport::Hel, '2026-10-29 11:30', '2026-10-29 14:00', 'AY2'),
            ],
            Money::fromEuros(150),
            RoutePattern::ViaHelsinkiWithTrain,
        );

        self::assertNotNull(RuleSet::standard(Direction::NlToFi)->rejectionReason($it));
    }

    public function test_a_through_ticket_without_a_stop_is_rejected(): void
    {
        // Only 1-connection flights for TKU<>AMS.
        $it = new Itinerary(
            Carrier::Klm,
            [self::segment(Airport::Ams, Airport::Tku, '2026-10-29 09:00', '2026-10-29 12:00', 'KL1')],
            Money::fromEuros(180),
            RoutePattern::ThroughTicket,
        );

        self::assertNotNull(RuleSet::standard(Direction::NlToFi)->rejectionReason($it));
    }

    public function test_filter_keeps_only_valid_options_and_renumbers(): void
    {
        $good = self::viaHel('2026-10-29 13:00', '2026-10-29 16:45', Carrier::Finnair);
        $bad = self::viaHel('2026-10-29 18:30', '2026-10-29 22:15', Carrier::Finnair);

        $kept = RuleSet::standard(Direction::NlToFi)->filter([$bad, $good, $bad]);

        self::assertCount(1, $kept);
        self::assertSame([0], array_keys($kept));
        self::assertSame($good, $kept[0]);
    }

    private static function viaHel(string $dep, string $arr, Carrier $carrier): Itinerary
    {
        return new Itinerary(
            $carrier,
            [self::segment(Airport::Ams, Airport::Hel, $dep, $arr, 'AY842')],
            Money::fromEuros(134),
            RoutePattern::ViaHelsinkiWithTrain,
        );
    }

    private static function segment(Airport $from, Airport $to, string $dep, string $arr, string $nr): Segment
    {
        return new Segment($from, $to, new \DateTimeImmutable($dep), new \DateTimeImmutable($arr), $nr);
    }
}
