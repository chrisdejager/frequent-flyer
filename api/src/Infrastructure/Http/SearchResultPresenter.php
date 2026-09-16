<?php

declare(strict_types=1);

namespace App\Infrastructure\Http;

use App\Application\SearchFlights\PricedItinerary;
use App\Application\SearchFlights\SearchResult;
use App\Domain\Flight\Segment;

final readonly class SearchResultPresenter
{
    /**
     * @return array<string, mixed>
     */
    public function present(SearchResult $result): array
    {
        return [
            'traveller' => [
                'id' => $result->traveller->id,
                'name' => $result->traveller->name,
            ],
            'searchedAt' => $result->searchedAt->format(\DATE_ATOM),
            'options' => array_map($this->option(...), $result->options),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function option(PricedItinerary $priced): array
    {
        $itinerary = $priced->itinerary;
        $cost = $priced->cost;

        return [
            'carrier' => $itinerary->carrier->value,
            'carrierLabel' => $itinerary->carrier->label(),
            'pattern' => $itinerary->pattern->value,
            'departAt' => $itinerary->departAt()->format(\DATE_ATOM),
            'arriveAt' => $itinerary->arriveAt()->format(\DATE_ATOM),
            'stops' => $itinerary->stopCount(),
            'via' => $itinerary->connectsVia()?->value,
            'connectionMinutes' => $itinerary->connectionMinutes(),
            'flightNumbers' => array_map(
                static fn (Segment $s): string => $s->flightNumber,
                $itinerary->segments,
            ),
            'cost' => [
                'basePrice' => $cost->basePrice->cents,
                'baggageCost' => $cost->baggageCost->cents,
                'trainCost' => $cost->trainCost->cents,
                'total' => $cost->total()->cents,
                'totalFormatted' => $cost->total()->format(),
                'indicative' => $cost->indicative,
            ],
        ];
    }
}
