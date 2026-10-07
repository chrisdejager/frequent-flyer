<?php

declare(strict_types=1);

namespace App\Domain\Rules;

use App\Domain\Flight\Direction;
use App\Domain\Flight\Itinerary;
use App\Domain\Flight\RoutePattern;

/**
 * Outbound: you must catch the train to Turku, so arrival no later than 21:00.
 * Return: no night departure from Turku, so departure no earlier than 12:00.
 *
 * Only applies to the train pattern. A through ticket to TKU has no
 * connecting train and is not checked here.
 */
final readonly class HelsinkiTimeWindow implements ItineraryRule
{
    public function __construct(
        private Direction $direction,
        private int $latestArrivalMinutes = 21 * 60,
        private int $earliestDepartureMinutes = 12 * 60,
    ) {
    }

    public function isSatisfiedBy(Itinerary $itinerary): bool
    {
        if (RoutePattern::ViaHelsinkiWithTrain !== $itinerary->pattern) {
            return true;
        }

        if ($this->direction->isOutbound()) {
            // "no later than 21:00" is inclusive: exactly 21:00 is still allowed.
            return self::minutesOfDay($itinerary->arriveAt()) <= $this->latestArrivalMinutes;
        }

        return self::minutesOfDay($itinerary->departAt()) >= $this->earliestDepartureMinutes;
    }

    public function reasonFor(Itinerary $itinerary): string
    {
        return $this->direction->isOutbound()
            ? 'Arrival in Helsinki after 21:00 — train to Turku no longer reachable.'
            : 'Departure from Helsinki before 12:00.';
    }

    private static function minutesOfDay(\DateTimeImmutable $moment): int
    {
        return ((int) $moment->format('H')) * 60 + (int) $moment->format('i');
    }
}
