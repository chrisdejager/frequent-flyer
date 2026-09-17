<?php

declare(strict_types=1);

namespace App\Application\SearchFlights;

use App\Domain\Flight\Airport;
use App\Domain\Flight\Itinerary;
use App\Domain\Flight\RoutePattern;
use App\Domain\Port\FlightPriceSource;
use App\Domain\Port\PriceHistory;
use App\Domain\Port\TrainPriceSource;
use App\Domain\Pricing\TotalCostCalculator;
use App\Domain\Rules\RuleSet;
use App\Domain\Traveller\Traveller;

final readonly class SearchFlightsHandler
{
    public function __construct(
        private FlightPriceSource $flights,
        private TrainPriceSource $trains,
        private TotalCostCalculator $calculator,
        private PriceHistory $history,
    ) {
    }

    public function __invoke(SearchFlightsQuery $query): SearchResult
    {
        $traveller = Traveller::byId($query->travellerId);
        $rules = RuleSet::standard($query->direction);
        $searchedAt = new \DateTimeImmutable();

        $candidates = [
            ...$this->fetch(Airport::Hel, $query),
            ...$this->fetch(Airport::Tku, $query),
        ];

        $valid = $rules->filter($candidates);

        // Alleen betalen voor een treinprijs als de calculator hem gebruikt én er
        // een HEL-optie overblijft. Het ophalen kost een Apify-run; met de transfer
        // uit zou dat een run zijn voor een bedrag dat direct wordt weggegooid.
        $trainCost = $this->calculator->usesTrainCost() && $this->needsTrainPrice($valid)
            ? $this->trains->priceFor($query->date)
            : null;

        $ranked = [];
        $unranked = [];

        foreach ($valid as $itinerary) {
            $this->history->record($itinerary, $searchedAt);

            $priced = new PricedItinerary(
                $itinerary,
                $this->calculator->calculate($itinerary, $traveller, $trainCost),
            );

            // Opties met een bekende treinprijs worden gerangschikt op totaalprijs.
            // Opties met een onbekende treinprijs (trein HEL–TKU niet opgehaald) staan
            // achteraan in de volgorde van binnenkomst — er wordt geen bedrag verzonnen.
            if ($priced->cost->trainCostKnown) {
                $ranked[] = $priced;
            } else {
                $unranked[] = $priced;
            }
        }

        usort(
            $ranked,
            static fn (PricedItinerary $a, PricedItinerary $b): int
                => $a->cost->total()->cents <=> $b->cost->total()->cents,
        );

        return new SearchResult([...$ranked, ...$unranked], $traveller, $searchedAt);
    }

    /**
     * @return Itinerary[]
     */
    private function fetch(Airport $finnishEnd, SearchFlightsQuery $query): array
    {
        return $query->direction->isOutbound()
            ? $this->flights->search(Airport::Ams, $finnishEnd, $query->date)
            : $this->flights->search($finnishEnd, Airport::Ams, $query->date);
    }

    /**
     * @param Itinerary[] $itineraries
     */
    private function needsTrainPrice(array $itineraries): bool
    {
        foreach ($itineraries as $itinerary) {
            if (RoutePattern::ViaHelsinkiWithTrain === $itinerary->pattern) {
                return true;
            }
        }

        return false;
    }
}
