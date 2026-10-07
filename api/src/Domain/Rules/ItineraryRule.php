<?php

declare(strict_types=1);

namespace App\Domain\Rules;

use App\Domain\Flight\Itinerary;

interface ItineraryRule
{
    public function isSatisfiedBy(Itinerary $itinerary): bool;

    /** Explanation that can be shown to the user if the rule rejects. */
    public function reasonFor(Itinerary $itinerary): string;
}
