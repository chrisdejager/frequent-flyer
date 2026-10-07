<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

use App\Domain\Flight\Itinerary;
use App\Domain\Flight\Money;
use App\Domain\Flight\RoutePattern;

final readonly class TotalCostCalculator
{
    public function __construct(
        /**
         * Is the Helsinki–Turku transfer enabled?
         *
         * Deliberately without a default value: every caller must choose explicitly, so
         * new wiring does not silently go the wrong way.
         */
        private bool $trainCostEnabled,
    ) {
    }

    /**
     * Does it make sense to fetch a train price?
     *
     * The caller pays for fetching (an Apify run), so they must be able to
     * know whether the result is used. Here, not in the caller, so there is
     * one place that knows the switch.
     */
    public function usesTrainCost(): bool
    {
        return $this->trainCostEnabled;
    }

    public function calculate(Itinerary $itinerary, ?Money $trainCost): TotalCost
    {
        $isHelRoute = RoutePattern::ViaHelsinkiWithTrain === $itinerary->pattern;

        if (!$isHelRoute) {
            // Through ticket: no train leg, so nothing is missing.
            $train = Money::zero();
            $trainCostKnown = true;
            $trainCostIncluded = true;
        } elseif (!$this->trainCostEnabled) {
            // Transfer temporarily disabled. The option continues to participate in sorting
            // (trainCostKnown remains true), but the total is demonstrably incomplete —
            // therefore the UI must be able to report it via $trainCostIncluded.
            $train = Money::zero();
            $trainCostKnown = true;
            $trainCostIncluded = false;
        } else {
            $train = $trainCost ?? Money::zero();
            $trainCostKnown = null !== $trainCost;
            $trainCostIncluded = true;
        }

        return new TotalCost(
            basePrice:         $itinerary->basePrice,
            trainCost:         $train,
            indicative:        $isHelRoute && !$trainCostKnown,
            trainCostKnown:    $trainCostKnown,
            trainCostIncluded: $trainCostIncluded,
        );
    }
}
