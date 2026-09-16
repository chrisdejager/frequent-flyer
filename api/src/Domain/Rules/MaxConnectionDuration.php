<?php

declare(strict_types=1);

namespace App\Domain\Rules;

use App\Domain\Flight\Itinerary;

final readonly class MaxConnectionDuration implements ItineraryRule
{
    public function __construct(private int $maxMinutes = 120)
    {
    }

    public function isSatisfiedBy(Itinerary $itinerary): bool
    {
        $minutes = $itinerary->connectionMinutes();

        return null === $minutes || $minutes <= $this->maxMinutes;
    }

    public function reasonFor(Itinerary $itinerary): string
    {
        return sprintf('Overstaptijd langer dan %d minuten.', $this->maxMinutes);
    }
}
