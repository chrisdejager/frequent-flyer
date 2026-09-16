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

        // Alleen betalen voor een treinprijs als er een HEL-optie overblijft.
        $trainCost = $this->needsTrainPrice($valid)
            ? $this->trains->priceFor($query->date)
            : null;

        $options = [];

        foreach ($valid as $itinerary) {
            $this->history->record($itinerary, $searchedAt);

            $options[] = new PricedItinerary(
                $itinerary,
                $this->calculator->calculate($itinerary, $traveller, $trainCost),
            );
        }

        usort(
            $options,
            static fn (PricedItinerary $a, PricedItinerary $b): int
                => $a->cost->total()->cents <=> $b->cost->total()->cents,
        );

        return new SearchResult($options, $traveller, $searchedAt);
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
