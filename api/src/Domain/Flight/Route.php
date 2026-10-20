<?php

declare(strict_types=1);

namespace App\Domain\Flight;

/** One origin–destination pair to search, e.g. AMS→HEL. */
final readonly class Route
{
    public function __construct(
        public Airport $from,
        public Airport $to,
    ) {
    }
}
