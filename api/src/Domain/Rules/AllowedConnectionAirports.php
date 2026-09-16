<?php

declare(strict_types=1);

namespace App\Domain\Rules;

use App\Domain\Flight\Airport;
use App\Domain\Flight\Itinerary;
use App\Domain\Flight\RoutePattern;

/**
 * Bij een doorgaand ticket zijn alleen Stockholm en Kopenhagen zinvolle
 * overstappunten. Helsinki valt hier bewust buiten: dat is patroon
 * via-Helsinki-met-trein, dat apart geprijsd wordt.
 */
final readonly class AllowedConnectionAirports implements ItineraryRule
{
    private const ALLOWED = [Airport::Arn, Airport::Cph];

    public function isSatisfiedBy(Itinerary $itinerary): bool
    {
        if (RoutePattern::ThroughTicket !== $itinerary->pattern) {
            return true;
        }

        $via = $itinerary->connectsVia();

        return null === $via || in_array($via, self::ALLOWED, true);
    }

    public function reasonFor(Itinerary $itinerary): string
    {
        return sprintf(
            'Overstap via %s wordt bij een doorgaand ticket niet overwogen.',
            $itinerary->connectsVia()?->value ?? '?',
        );
    }
}
