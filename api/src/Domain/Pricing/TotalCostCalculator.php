<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

use App\Domain\Flight\Itinerary;
use App\Domain\Flight\Money;
use App\Domain\Flight\RoutePattern;
use App\Domain\Traveller\Traveller;

final readonly class TotalCostCalculator
{
    public function __construct(
        private BaggagePolicy $baggage,
        private FareUpgradeRates $rates,
    ) {
    }

    public function calculate(Itinerary $itinerary, Traveller $traveller, ?Money $trainCost): TotalCost
    {
        $baggageCost = $this->baggage->costFor(
            $itinerary->carrier,
            $traveller->statusFor($itinerary->carrier),
        );

        $train = RoutePattern::ViaHelsinkiWithTrain === $itinerary->pattern
            ? ($trainCost ?? Money::zero())
            : Money::zero();

        // De uitkomst is alleen onzeker als er daadwerkelijk een geschat
        // tarief in meegerekend is.
        $indicative = !$baggageCost->isZero() && $this->rates->isEstimate($itinerary->carrier);

        return new TotalCost($itinerary->basePrice, $baggageCost, $train, $indicative);
    }
}
