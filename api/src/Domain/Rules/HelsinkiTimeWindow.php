<?php

declare(strict_types=1);

namespace App\Domain\Rules;

use App\Domain\Flight\Direction;
use App\Domain\Flight\Itinerary;
use App\Domain\Flight\RoutePattern;

/**
 * Heen: je moet de trein naar Turku nog halen, dus aankomst uiterlijk 21:00.
 * Terug: geen nachtelijk vertrek uit Turku, dus vertrek niet vóór 12:00.
 *
 * Geldt alleen voor het patroon met trein. Een doorgaand ticket naar TKU
 * heeft geen aansluitende trein en wordt hier niet op getoetst.
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
            // "uiterlijk 21:00" is inclusief: precies 21:00 mag nog.
            return self::minutesOfDay($itinerary->arriveAt()) <= $this->latestArrivalMinutes;
        }

        return self::minutesOfDay($itinerary->departAt()) >= $this->earliestDepartureMinutes;
    }

    public function reasonFor(Itinerary $itinerary): string
    {
        return $this->direction->isOutbound()
            ? 'Aankomst in Helsinki na 21:00 — trein naar Turku niet meer haalbaar.'
            : 'Vertrek uit Helsinki vóór 12:00.';
    }

    private static function minutesOfDay(\DateTimeImmutable $moment): int
    {
        return ((int) $moment->format('H')) * 60 + (int) $moment->format('i');
    }
}
