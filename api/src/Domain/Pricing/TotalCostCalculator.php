<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

use App\Domain\Flight\Itinerary;

final readonly class TotalCostCalculator
{
    public function calculate(Itinerary $itinerary): TotalCost
    {
        return new TotalCost(basePrice: $itinerary->basePrice);
    }
}
