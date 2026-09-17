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
        /**
         * Staat de transfer Helsinki–Turku aan?
         *
         * Bewust zonder standaardwaarde: elke aanroeper moet expliciet kiezen, zodat
         * een nieuwe bedrading niet stil de verkeerde kant op valt.
         */
        private bool $trainCostEnabled,
    ) {
    }

    public function calculate(Itinerary $itinerary, Traveller $traveller, ?Money $trainCost): TotalCost
    {
        $baggageCost = $this->baggage->costFor(
            $itinerary->carrier,
            $traveller->statusFor($itinerary->carrier),
        );

        $isHelRoute = RoutePattern::ViaHelsinkiWithTrain === $itinerary->pattern;

        if (!$isHelRoute) {
            // Doorgaand ticket: geen treinbeen, dus er ontbreekt niets.
            $train = Money::zero();
            $trainCostKnown = true;
            $trainCostIncluded = true;
        } elseif (!$this->trainCostEnabled) {
            // Transfer tijdelijk uitgezet. De optie blijft meedoen aan de sortering
            // (trainCostKnown blijft true), maar het totaal is aantoonbaar onvolledig —
            // daarom moet de UI het via $trainCostIncluded kunnen melden.
            $train = Money::zero();
            $trainCostKnown = true;
            $trainCostIncluded = false;
        } else {
            $train = $trainCost ?? Money::zero();
            $trainCostKnown = null !== $trainCost;
            $trainCostIncluded = true;
        }

        // Indicatief als: de bagagekosten geschat zijn, OF de treinprijs onbekend is.
        $indicative = (!$baggageCost->isZero() && $this->rates->isEstimate($itinerary->carrier))
            || ($isHelRoute && !$trainCostKnown);

        return new TotalCost(
            basePrice:         $itinerary->basePrice,
            baggageCost:       $baggageCost,
            trainCost:         $train,
            indicative:        $indicative,
            trainCostKnown:    $trainCostKnown,
            trainCostIncluded: $trainCostIncluded,
        );
    }
}
