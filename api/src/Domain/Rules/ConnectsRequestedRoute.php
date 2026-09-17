<?php

declare(strict_types=1);

namespace App\Domain\Rules;

use App\Domain\Flight\Airport;
use App\Domain\Flight\Itinerary;

final readonly class ConnectsRequestedRoute implements ItineraryRule
{
    public function __construct(
        private Airport $from,
        private Airport $to,
    ) {
    }

    public function isSatisfiedBy(Itinerary $itinerary): bool
    {
        return $itinerary->origin() === $this->from && $itinerary->destination() === $this->to;
    }

    public function reasonFor(Itinerary $itinerary): string
    {
        return sprintf(
            'Vlucht %s→%s past niet bij gevraagde route %s→%s.',
            $itinerary->origin()->value,
            $itinerary->destination()->value,
            $this->from->value,
            $this->to->value,
        );
    }
}
