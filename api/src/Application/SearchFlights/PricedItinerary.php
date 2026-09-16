<?php

declare(strict_types=1);

namespace App\Application\SearchFlights;

use App\Domain\Flight\Itinerary;
use App\Domain\Pricing\TotalCost;

final readonly class PricedItinerary
{
    public function __construct(
        public Itinerary $itinerary,
        public TotalCost $cost,
    ) {
    }
}
