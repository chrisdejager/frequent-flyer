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
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Tests the toggle through the real container.
 *
 * TotalCostCalculatorTest passes trainCostEnabled itself and thus can never
 * prove that TRAIN_COST_ENABLED is truly wired. Exactly as this happened before:
 * a factor-100 error in a parameter remained invisible because the tests filled the value
 * themselves. This test asks the container what it truly does.
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
            Money::fromEuros(22.50),
        );

        $enabled = filter_var($_ENV['TRAIN_COST_ENABLED'] ?? '0', FILTER_VALIDATE_BOOL);

        if ($enabled) {
            self::assertSame(2250, $cost->trainCost->cents, 'toggle on → train price counts');
            self::assertTrue($cost->trainCostIncluded);

            return;
        }

        self::assertTrue($cost->trainCost->isZero(), 'toggle off → no train costs in the total');
        self::assertSame(13400, $cost->total()->cents, 'ticket price only');
        self::assertTrue($cost->trainCostKnown, 'the option must continue to participate in ranking');
        self::assertFalse($cost->trainCostIncluded, 'the UI must be able to report the missing transfer');
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
