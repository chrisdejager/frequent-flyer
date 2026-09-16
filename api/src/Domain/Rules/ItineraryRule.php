<?php

declare(strict_types=1);

namespace App\Domain\Rules;

use App\Domain\Flight\Itinerary;

interface ItineraryRule
{
    public function isSatisfiedBy(Itinerary $itinerary): bool;

    /** Uitleg die aan de gebruiker getoond kan worden als de regel afwijst. */
    public function reasonFor(Itinerary $itinerary): string;
}
