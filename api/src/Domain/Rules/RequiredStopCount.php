<?php

declare(strict_types=1);

namespace App\Domain\Rules;

use App\Domain\Flight\Itinerary;
use App\Domain\Flight\RoutePattern;

/**
 * Simplification: HEL<>AMS direct only, TKU<>AMS only with 1 connection.
 */
final readonly class RequiredStopCount implements ItineraryRule
{
    /** @var array<string, int> */
    private const REQUIRED = [
        RoutePattern::Helsinki->value => 0,
        RoutePattern::ThroughTicket->value => 1,
    ];

    public function isSatisfiedBy(Itinerary $itinerary): bool
    {
        return $itinerary->stopCount() === self::REQUIRED[$itinerary->pattern->value];
    }

    public function reasonFor(Itinerary $itinerary): string
    {
        return sprintf(
            '%s requires %d connection(s), this option has %d.',
            $itinerary->pattern->value,
            self::REQUIRED[$itinerary->pattern->value],
            $itinerary->stopCount(),
        );
    }
}
