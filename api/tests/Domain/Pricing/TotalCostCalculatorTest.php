<?php

declare(strict_types=1);

namespace App\Tests\Domain\Pricing;

use App\Domain\Flight\Airport;
use App\Domain\Flight\Carrier;
use App\Domain\Flight\Itinerary;
use App\Domain\Flight\Money;
use App\Domain\Flight\RoutePattern;
use App\Domain\Flight\Segment;
use App\Domain\Pricing\BaggagePolicy;
use App\Domain\Pricing\FareUpgradeRates;
use App\Domain\Pricing\TotalCostCalculator;
use App\Domain\Traveller\Traveller;
use PHPUnit\Framework\TestCase;

final class TotalCostCalculatorTest extends TestCase
{
    private TotalCostCalculator $calculator;

    protected function setUp(): void
    {
        $rates = FareUpgradeRates::fromConfig(['AY' => 35, 'KL' => 40]);
        $this->calculator = new TotalCostCalculator(new BaggagePolicy($rates), $rates, trainCostEnabled: true);
    }

    private static function calculatorWithTrainEnabled(bool $enabled): TotalCostCalculator
    {
        $rates = FareUpgradeRates::fromConfig(['AY' => 35, 'KL' => 40]);

        return new TotalCostCalculator(new BaggagePolicy($rates), $rates, trainCostEnabled: $enabled);
    }

    public function test_with_the_train_disabled_a_hel_route_costs_zero_but_stays_ranked(): void
    {
        $cost = self::calculatorWithTrainEnabled(false)->calculate(
            self::viaHel(Carrier::Finnair, 134),
            Traveller::chris(),
            Money::fromEuros(22.50),
        );

        self::assertTrue($cost->trainCost->isZero(), 'trein staat uit → geen treinkosten in het totaal');
        self::assertSame(13400, $cost->total()->cents, 'alleen de ticketprijs (Finnair Silver: gratis bagage)');
        self::assertTrue($cost->trainCostKnown,
            'trainCostKnown moet true blijven, anders belandt élke Helsinki-optie in de ongerangschikte bak');
        self::assertFalse($cost->trainCostIncluded,
            'de UI moet kunnen zien dat de transfer HEL→TKU niet in dit totaal zit');
    }

    public function test_with_the_train_disabled_a_through_ticket_is_not_marked_as_incomplete(): void
    {
        $cost = self::calculatorWithTrainEnabled(false)->calculate(
            self::throughTicket(Carrier::Sas, 150),
            Traveller::chris(),
            null,
        );

        self::assertTrue($cost->trainCostIncluded,
            'een doorgaand ticket heeft geen treinbeen, dus er ontbreekt niets');
    }

    public function test_with_the_train_enabled_the_price_is_still_added(): void
    {
        $cost = self::calculatorWithTrainEnabled(true)->calculate(
            self::viaHel(Carrier::Finnair, 134),
            Traveller::chris(),
            Money::fromEuros(22.50),
        );

        self::assertSame(2250, $cost->trainCost->cents);
        self::assertTrue($cost->trainCostIncluded);
    }

    public function test_train_cost_is_added_for_the_helsinki_pattern(): void
    {
        $cost = $this->calculator->calculate(
            self::viaHel(Carrier::Finnair, 134),
            Traveller::chris(),
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
            Traveller::chris(),
            Money::fromEuros(22.50),
        );

        self::assertTrue($cost->trainCost->isZero());
        self::assertSame(15000, $cost->total()->cents);
    }

    public function test_the_partner_pays_more_than_chris_on_the_same_finnair_flight(): void
    {
        $flight = self::viaHel(Carrier::Finnair, 134);
        $train = Money::fromEuros(22.50);

        $chris = $this->calculator->calculate($flight, Traveller::chris(), $train);
        $partner = $this->calculator->calculate($flight, Traveller::partner(), $train);

        self::assertSame(15650, $chris->total()->cents);
        self::assertSame(19150, $partner->total()->cents, 'partner betaalt €35 handbagage extra');
    }

    public function test_klm_results_are_flagged_as_indicative(): void
    {
        $cost = $this->calculator->calculate(
            self::throughTicket(Carrier::Klm, 120),
            Traveller::chris(),
            null,
        );

        self::assertTrue($cost->indicative);
        self::assertSame(16000, $cost->total()->cents);
    }

    public function test_finnair_results_are_not_indicative(): void
    {
        $cost = $this->calculator->calculate(
            self::viaHel(Carrier::Finnair, 134),
            Traveller::partner(),
            Money::fromEuros(22.50),
        );

        self::assertFalse($cost->indicative, 'Finnair hanteert een vast tarief');
    }

    public function test_sas_is_never_indicative_because_no_estimate_is_used(): void
    {
        $cost = $this->calculator->calculate(
            self::throughTicket(Carrier::Sas, 150),
            Traveller::partner(),
            null,
        );

        self::assertFalse($cost->indicative);
        self::assertTrue($cost->baggageCost->isZero());
    }

    public function test_klm_is_not_indicative_when_the_upgrade_rate_is_zero(): void
    {
        // Een KLM-reisoptie, maar met nultarief voor bagagekosten
        $rates = FareUpgradeRates::fromConfig(['AY' => 16, 'KL' => 0]);
        $calculator = new TotalCostCalculator(new BaggagePolicy($rates), $rates, trainCostEnabled: true);

        $cost = $calculator->calculate(
            self::throughTicket(Carrier::Klm, 120),
            Traveller::chris(),
            null,
        );

        self::assertTrue($cost->baggageCost->isZero(), 'bagagekosten moeten nul zijn');
        self::assertFalse($cost->indicative, 'geen schatting dus niet indicatief');
    }

    public function test_null_train_price_for_helsinki_pattern_marks_cost_unknown_and_indicative(): void
    {
        $cost = $this->calculator->calculate(
            self::viaHel(Carrier::Finnair, 134),
            Traveller::chris(),
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
            Traveller::chris(),
            null,
        );

        self::assertTrue($cost->trainCostKnown, 'doorgaand ticket heeft geen trein, dus niets onbekends');
    }

    public function test_known_train_price_sets_trainCostKnown_true(): void
    {
        $cost = $this->calculator->calculate(
            self::viaHel(Carrier::Finnair, 134),
            Traveller::chris(),
            Money::fromEuros(22.50),
        );

        self::assertTrue($cost->trainCostKnown);
    }

    public function test_null_train_price_with_non_zero_baggage_is_still_indicative(): void
    {
        // Dekt het kruispunt: isHelRoute=true + trainCostKnown=false + baggageCost≠0.
        // Levende mutant: || ($isHelRoute && !$trainCostKnown && $baggageCost->isZero())
        // zou de indicatief-markering weggooien op het standaardpad (partner + Finnair).
        $cost = $this->calculator->calculate(
            self::viaHel(Carrier::Finnair, 134),
            Traveller::partner(),
            null,
        );

        self::assertFalse($cost->baggageCost->isZero(), 'partner betaalt €35 bagage — anders bewijst de test het kruispunt niet');
        self::assertFalse($cost->trainCostKnown, 'treinprijs null → trainCostKnown moet false zijn');
        self::assertTrue($cost->indicative, 'onbekende treinprijs moet indicatief zijn, ook als baggageCost niet nul is');
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
