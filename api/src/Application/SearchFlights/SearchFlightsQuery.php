<?php

declare(strict_types=1);

namespace App\Application\SearchFlights;

use App\Domain\Flight\Direction;

final readonly class SearchFlightsQuery
{
    public function __construct(
        public \DateTimeImmutable $date,
        public Direction $direction,
        public string $travellerId,
    ) {
    }
}
