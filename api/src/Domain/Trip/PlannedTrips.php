<?php

declare(strict_types=1);

namespace App\Domain\Trip;

use App\Domain\Flight\Direction;

/**
 * Fase 1 houdt de planning hier vast. Fase 4 vervangt dit door Google Calendar;
 * alleen deze klasse hoeft dan te wijzigen.
 */
final readonly class PlannedTrips
{
    /**
     * Bewust niet op datum gezet: zo moet `all()` echt sorteren en heeft de
     * sorteertest iets om te bewaken. Uit de agenda komt de volgorde straks
     * ook niet gegarandeerd goed.
     */
    private const TRIPS = [
        ['2026-10-29', 'nl-fi', 'partner'],
        ['2026-10-25', 'fi-nl', 'partner'],
    ];

    /**
     * @return PlannedTrip[] op datum gesorteerd
     */
    public function all(): array
    {
        $trips = array_map(
            static fn (array $row): PlannedTrip => new PlannedTrip(
                new \DateTimeImmutable($row[0]),
                Direction::from($row[1]),
                $row[2],
            ),
            self::TRIPS,
        );

        usort($trips, static fn (PlannedTrip $a, PlannedTrip $b): int => $a->date <=> $b->date);

        return $trips;
    }
}
