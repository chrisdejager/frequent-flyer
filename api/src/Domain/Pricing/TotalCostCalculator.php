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

        $isHelRoute = RoutePattern::ViaHelsinkiWithTrain === $itinerary->pattern;
        $trainCostKnown = true;

        if ($isHelRoute) {
            $train = $trainCost ?? Money::zero();
            $trainCostKnown = null !== $trainCost;
        } else {
            $train = Money::zero();
        }

        // Indicatief als: de bagagekosten geschat zijn, OF de treinprijs onbekend is.
        $indicative = (!$baggageCost->isZero() && $this->rates->isEstimate($itinerary->carrier))
            || ($isHelRoute && !$trainCostKnown);

        return new TotalCost(
            basePrice:      $itinerary->basePrice,
            baggageCost:    $baggageCost,
            trainCost:      $train,
            indicative:     $indicative,
            trainCostKnown: $trainCostKnown,
        );
    }
}
