<?php

declare(strict_types=1);

namespace App\Domain\Rules;

use App\Domain\Flight\Airport;
use App\Domain\Flight\Itinerary;
use App\Domain\Flight\RoutePattern;

/**
 * For a through ticket, only Stockholm and Copenhagen are meaningful
 * connection points. Helsinki is intentionally excluded here: that is the
 * via-Helsinki-with-train pattern, which is priced separately.
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
            'Connection via %s is not considered for a through ticket.',
            $itinerary->connectsVia()?->value ?? '?',
        );
    }
}
