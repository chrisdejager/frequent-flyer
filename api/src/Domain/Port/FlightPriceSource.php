<?php

declare(strict_types=1);

namespace App\Domain\Port;

use App\Domain\Flight\FlightSourceUnavailable;
use App\Domain\Flight\Itinerary;
use App\Domain\Flight\Route;

interface FlightPriceSource
{
    /**
     * All routes in one call, so an adapter can fetch them concurrently.
     *
     * @param list<Route> $routes
     *
     * @return Itinerary[] unfiltered, all routes together; rules are applied later
     *
     * @throws FlightSourceUnavailable when the source cannot be queried at all
     */
    public function search(array $routes, \DateTimeImmutable $date): array;
}
