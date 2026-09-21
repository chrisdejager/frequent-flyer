<?php

declare(strict_types=1);

namespace App\Domain\Rules;

use App\Domain\Flight\Itinerary;
use App\Domain\Flight\RoutePattern;

/**
 * Vereenvoudiging: HEL<>AMS alleen direct, TKU<>AMS alleen met 1 overstap.
 */
final readonly class RequiredStopCount implements ItineraryRule
{
    /** @var array<string, int> */
    private const REQUIRED = [
        RoutePattern::ViaHelsinkiWithTrain->value => 0,
        RoutePattern::ThroughTicket->value => 1,
    ];

    public function isSatisfiedBy(Itinerary $itinerary): bool
    {
        return $itinerary->stopCount() === self::REQUIRED[$itinerary->pattern->value];
    }

    public function reasonFor(Itinerary $itinerary): string
    {
        return sprintf(
            '%s vereist %d overstap(pen), deze optie heeft er %d.',
            $itinerary->pattern->value,
            self::REQUIRED[$itinerary->pattern->value],
            $itinerary->stopCount(),
        );
    }
}
