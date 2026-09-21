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
    private TotalCostCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new TotalCostCalculator(trainCostEnabled: true);
    }

    public function test_with_the_train_disabled_a_hel_route_costs_zero_but_stays_ranked(): void
    {
        $cost = (new TotalCostCalculator(trainCostEnabled: false))->calculate(
            self::viaHel(Carrier::Finnair, 134),
            Money::fromEuros(22.50),
        );

        self::assertTrue($cost->trainCost->isZero(), 'trein staat uit → geen treinkosten in het totaal');
        self::assertSame(13400, $cost->total()->cents, 'alleen de ticketprijs');
        self::assertTrue($cost->trainCostKnown,
            'trainCostKnown moet true blijven, anders belandt élke Helsinki-optie in de ongerangschikte bak');
        self::assertFalse($cost->trainCostIncluded,
            'de UI moet kunnen zien dat de transfer HEL→TKU niet in dit totaal zit');
    }

    public function test_with_the_train_disabled_a_through_ticket_is_not_marked_as_incomplete(): void
    {
        $cost = (new TotalCostCalculator(trainCostEnabled: false))->calculate(
            self::throughTicket(Carrier::Sas, 150),
            null,
        );

        self::assertTrue($cost->trainCostIncluded,
            'een doorgaand ticket heeft geen treinbeen, dus er ontbreekt niets');
    }

    public function test_with_the_train_enabled_the_price_is_still_added(): void
    {
        $cost = $this->calculator->calculate(
            self::viaHel(Carrier::Finnair, 134),
            Money::fromEuros(22.50),
        );

        self::assertSame(2250, $cost->trainCost->cents);
        self::assertTrue($cost->trainCostIncluded);
    }

    public function test_train_cost_is_added_for_the_helsinki_pattern(): void
    {
        $cost = $this->calculator->calculate(
            self::viaHel(Carrier::Finnair, 134),
            Money::fromEuros(22.50),
        );

        self::assertSame(13400, $cost->basePrice->cents);
        self::assertSame(2250, $cost->trainCost->cents);
        self::assertSame(15650, $cost->total()->cents);
    }

    public function test_train_cost_is_ignored_for_a_through_ticket(): void
    {
        $cost = $this->calculator->calculate(
            self::throughTicket(Carrier::Sas, 150),
            Money::fromEuros(22.50),
        );

        self::assertTrue($cost->trainCost->isZero());
        self::assertSame(15000, $cost->total()->cents);
    }

    public function test_finnair_results_are_not_indicative(): void
    {
        $cost = $this->calculator->calculate(
            self::viaHel(Carrier::Finnair, 134),
            Money::fromEuros(22.50),
        );

        self::assertFalse($cost->indicative, 'bekende treinprijs, geen bagageschatting meer → niet indicatief');
    }

    public function test_sas_through_ticket_is_not_indicative(): void
    {
        $cost = $this->calculator->calculate(
            self::throughTicket(Carrier::Sas, 150),
            null,
        );

        self::assertFalse($cost->indicative, 'doorgaand ticket heeft geen trein, dus niets onbekends');
    }

    public function test_null_train_price_for_helsinki_pattern_marks_cost_unknown_and_indicative(): void
    {
        $cost = $this->calculator->calculate(
            self::viaHel(Carrier::Finnair, 134),
            null,
        );

        self::assertFalse($cost->trainCostKnown, 'treinprijs null → trainCostKnown moet false zijn');
        self::assertTrue($cost->indicative, 'onbekende treinprijs → optie is indicatief');
        self::assertTrue($cost->trainCost->isZero(), 'rekent intern met nul zodat totaal klopt');
    }

    public function test_null_train_price_for_through_ticket_leaves_trainCostKnown_true(): void
    {
        $cost = $this->calculator->calculate(
            self::throughTicket(Carrier::Sas, 150),
            null,
        );

        self::assertTrue($cost->trainCostKnown, 'doorgaand ticket heeft geen trein, dus niets onbekends');
    }

    public function test_known_train_price_sets_trainCostKnown_true(): void
    {
        $cost = $this->calculator->calculate(
            self::viaHel(Carrier::Finnair, 134),
            Money::fromEuros(22.50),
        );

        self::assertTrue($cost->trainCostKnown);
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
