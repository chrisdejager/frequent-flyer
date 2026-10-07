<?php

declare(strict_types=1);

namespace App\Domain\Trip;

use App\Domain\Flight\Direction;

/**
 * Phase 1 keeps the planning here. Phase 4 replaces this with Google Calendar;
 * only this class needs to change then.
 */
final readonly class PlannedTrips
{
    /**
     * Deliberately not sorted by date: this way `all()` must really sort, and the
     * sort test has something to guard. From the calendar the order will later
     * also not be guaranteed correct.
     */
    private const TRIPS = [
        ['2026-10-29', 'nl-fi', 'partner'],
        ['2026-10-25', 'fi-nl', 'partner'],
    ];

    /**
     * @return PlannedTrip[] sorted by date
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
