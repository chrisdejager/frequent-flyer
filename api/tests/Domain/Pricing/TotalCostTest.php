<?php

declare(strict_types=1);

namespace App\Tests\Domain\Pricing;

use App\Domain\Flight\Money;
use App\Domain\Pricing\TotalCost;
use PHPUnit\Framework\TestCase;

final class TotalCostTest extends TestCase
{
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
