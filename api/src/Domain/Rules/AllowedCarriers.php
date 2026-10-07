<?php

declare(strict_types=1);

namespace App\Domain\Rules;

use App\Domain\Flight\Carrier;
use App\Domain\Flight\Itinerary;

final readonly class AllowedCarriers implements ItineraryRule
{
    /** @var Carrier[] */
    private array $allowed;

    public function __construct(Carrier ...$allowed)
    {
        $this->allowed = [] === $allowed
            ? [Carrier::Finnair, Carrier::Sas, Carrier::Klm]
            : $allowed;
    }

    public function isSatisfiedBy(Itinerary $itinerary): bool
    {
        return in_array($itinerary->carrier, $this->allowed, true);
    }

    public function reasonFor(Itinerary $itinerary): string
    {
        return sprintf('Airline %s is outside Finnair, SAS and KLM.', $itinerary->carrier->value);
    }
}
