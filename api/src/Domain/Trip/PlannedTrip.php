<?php

declare(strict_types=1);

namespace App\Domain\Trip;

use App\Domain\Flight\Direction;

final readonly class PlannedTrip
{
    public function __construct(
        public \DateTimeImmutable $date,
        public Direction $direction,
        public string $travellerId,
    ) {
    }
}
