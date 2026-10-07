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
        // Proves that total() itself doesn't change — the displayed amount stays €140
        $cost = new TotalCost(
            basePrice:         Money::fromEuros(140.00),
            trainCost:         Money::zero(),
            indicative:        true,
            trainCostKnown:    false,
            trainCostIncluded: true,
        );

        self::assertSame(14000, $cost->total()->cents,
            'total() shows €140 — the estimate should not be visible here');
    }
}
