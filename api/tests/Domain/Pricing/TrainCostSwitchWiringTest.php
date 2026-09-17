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
use App\Domain\Traveller\Traveller;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Toetst de schakelaar door de échte container.
 *
 * TotalCostCalculatorTest geeft trainCostEnabled zelf mee en kan daarom nooit
 * bewijzen dat TRAIN_COST_ENABLED werkelijk bedraad is. Precies zo bleef een
 * eerdere factor-100-fout in een parameter onzichtbaar: de tests vulden de waarde
 * zelf in. Deze test vraagt de container wat hij werkelijk doet.
 */
final class TrainCostSwitchWiringTest extends KernelTestCase
{
    public function test_the_container_applies_the_train_cost_switch_from_the_environment(): void
    {
        self::bootKernel();

        $calculator = self::getContainer()->get(TotalCostCalculator::class);
        self::assertInstanceOf(TotalCostCalculator::class, $calculator);

        $cost = $calculator->calculate(
            self::viaHel(),
            Traveller::chris(),
            Money::fromEuros(22.50),
        );

        $enabled = filter_var($_ENV['TRAIN_COST_ENABLED'] ?? '0', FILTER_VALIDATE_BOOL);

        if ($enabled) {
            self::assertSame(2250, $cost->trainCost->cents, 'schakelaar aan → treinprijs telt mee');
            self::assertTrue($cost->trainCostIncluded);

            return;
        }

        self::assertTrue($cost->trainCost->isZero(), 'schakelaar uit → geen treinkosten in het totaal');
        self::assertSame(13400, $cost->total()->cents, 'alleen de ticketprijs');
        self::assertTrue($cost->trainCostKnown, 'de optie moet blijven meedoen aan de sortering');
        self::assertFalse($cost->trainCostIncluded, 'de UI moet de ontbrekende transfer kunnen melden');
    }

    private static function viaHel(): Itinerary
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
