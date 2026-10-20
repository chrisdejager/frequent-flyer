<?php

declare(strict_types=1);

namespace App\Tests\Double;

use App\Domain\Flight\Airport;
use App\Domain\Flight\Itinerary;
use App\Domain\Flight\Route;
use App\Domain\Port\FlightPriceSource;

final class InMemoryFlightPriceSource implements FlightPriceSource
{
    /** @var array<string, Itinerary[]> */
    private array $byRoute = [];

    /** @var array<int, array{Airport, Airport, string}> */
    public array $calls = [];

    /** Number of routes per search() call: one entry per call. @var list<int> */
    public array $batches = [];

    /**
     * @param Itinerary[] $itineraries
     */
    public function give(Airport $from, Airport $to, array $itineraries): void
    {
        $this->byRoute[$from->value.'-'.$to->value] = $itineraries;
    }

    public function search(array $routes, \DateTimeImmutable $date): array
    {
        $this->batches[] = count($routes);
        $found = [];

        foreach ($routes as $route) {
            $this->calls[] = [$route->from, $route->to, $date->format('Y-m-d')];
            $found = [...$found, ...$this->byRoute[$route->from->value.'-'.$route->to->value] ?? []];
        }

        return $found;
    }
}
