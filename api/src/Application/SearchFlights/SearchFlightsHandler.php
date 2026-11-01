<?php

declare(strict_types=1);

namespace App\Application\SearchFlights;

use App\Domain\Flight\Airport;
use App\Domain\Flight\Route;
use App\Domain\Port\FlightPriceSource;
use App\Domain\Port\PriceHistory;
use App\Domain\Pricing\TotalCostCalculator;
use App\Domain\Rules\RuleSet;
use App\Domain\Traveller\Traveller;

final readonly class SearchFlightsHandler
{
    public function __construct(
        private FlightPriceSource $flights,
        private TotalCostCalculator $calculator,
        private PriceHistory $history,
    ) {
    }

    public function __invoke(SearchFlightsQuery $query): SearchResult
    {
        $traveller = Traveller::byId($query->travellerId);
        $rules = RuleSet::standard($query->direction);
        $searchedAt = new \DateTimeImmutable();

        // Both routes in one call, so the source can fetch them concurrently.
        $candidates = $this->flights->search(
            [$this->route(Airport::Hel, $query), $this->route(Airport::Tku, $query)],
            $query->date,
        );

        $options = [];

        foreach ($rules->filter($candidates) as $itinerary) {
            $this->history->record($itinerary, $searchedAt);

            $options[] = new PricedItinerary($itinerary, $this->calculator->calculate($itinerary));
        }

        usort(
            $options,
            static fn (PricedItinerary $a, PricedItinerary $b): int
                => $a->cost->total()->cents <=> $b->cost->total()->cents,
        );

        return new SearchResult($options, $traveller, $searchedAt);
    }

    private function route(Airport $finnishEnd, SearchFlightsQuery $query): Route
    {
        return $query->direction->isOutbound()
            ? new Route(Airport::Ams, $finnishEnd)
            : new Route($finnishEnd, Airport::Ams);
    }
}
