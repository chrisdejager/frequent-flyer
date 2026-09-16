<?php

declare(strict_types=1);

namespace App\Tests\Double;

use App\Domain\Flight\Airport;
use App\Domain\Flight\Itinerary;
use App\Domain\Port\FlightPriceSource;

final class InMemoryFlightPriceSource implements FlightPriceSource
{
    /** @var array<string, Itinerary[]> */
    private array $byRoute = [];

    /** @var array<int, array{Airport, Airport, string}> */
    public array $calls = [];

    /**
     * @param Itinerary[] $itineraries
     */
    public function give(Airport $from, Airport $to, array $itineraries): void
    {
        $this->byRoute[$from->value.'-'.$to->value] = $itineraries;
    }

    public function search(Airport $from, Airport $to, \DateTimeImmutable $date): array
    {
        $this->calls[] = [$from, $to, $date->format('Y-m-d')];

        return $this->byRoute[$from->value.'-'.$to->value] ?? [];
    }
}
