<?php

declare(strict_types=1);

namespace App\Tests\Domain\Pricing;

use App\Domain\Flight\Money;
use App\Domain\Pricing\TotalCost;
use PHPUnit\Framework\TestCase;

final class TotalCostTest extends TestCase
{
    public function test_ranking_total_uses_estimate_when_train_cost_unknown(): void
    {
        $cost = new TotalCost(
            basePrice:      Money::fromEuros(140.00),
            baggageCost:    Money::zero(),
            trainCost:      Money::zero(),
            indicative:     true,
            trainCostKnown: false,
        );

        $estimate = Money::fromEuros(35.00);

        self::assertSame(17500, $cost->rankingTotal($estimate)->cents,
            '€140 + schatting €35 = €175 voor rangschikking');
    }

    public function test_ranking_total_equals_total_when_train_cost_known(): void
    {
        $cost = new TotalCost(
            basePrice:      Money::fromEuros(134.00),
            baggageCost:    Money::zero(),
            trainCost:      Money::fromEuros(22.50),
            indicative:     false,
            trainCostKnown: true,
        );

        $estimate = Money::fromEuros(35.00);

        self::assertSame($cost->total()->cents, $cost->rankingTotal($estimate)->cents,
            'bij bekende treinprijs: rankingTotal == total, schatting niet gebruikt');
    }

    public function test_total_is_unchanged_when_train_cost_unknown(): void
    {
        // Bewijst dat total() zelf niet verandert — het getoonde bedrag blijft €140
        $cost = new TotalCost(
            basePrice:      Money::fromEuros(140.00),
            baggageCost:    Money::zero(),
            trainCost:      Money::zero(),
            indicative:     true,
            trainCostKnown: false,
        );

        self::assertSame(14000, $cost->total()->cents,
            'total() toont €140 — de schatting mag hier niet zichtbaar zijn');
    }
}
