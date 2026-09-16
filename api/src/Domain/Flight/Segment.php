<?php

declare(strict_types=1);

namespace App\Domain\Flight;

final readonly class Segment
{
    public function __construct(
        public Airport $from,
        public Airport $to,
        public \DateTimeImmutable $departAt,
        public \DateTimeImmutable $arriveAt,
        public string $flightNumber,
    ) {
        if ($arriveAt <= $departAt) {
            throw new \InvalidArgumentException('Aankomst moet na vertrek liggen.');
        }
    }
}
