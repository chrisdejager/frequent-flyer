<?php

declare(strict_types=1);

namespace App\Domain\Rules;

use App\Domain\Flight\Direction;
use App\Domain\Flight\Itinerary;

final readonly class RuleSet
{
    /** @var ItineraryRule[] */
    private array $rules;

    public function __construct(ItineraryRule ...$rules)
    {
        $this->rules = $rules;
    }

    public static function standard(Direction $direction): self
    {
        // Volgorde bepaalt welke afwijzingsreden je te zien krijgt als een
        // optie meerdere regels breekt. Van grofst naar fijnst.
        return new self(
            new AllowedCarriers(),
            new AllowedConnectionAirports(),
            new MaxConnectionDuration(120),
            new HelsinkiTimeWindow($direction),
        );
    }

    /**
     * @param Itinerary[] $itineraries
     *
     * @return Itinerary[] hernummerd vanaf 0
     */
    public function filter(array $itineraries): array
    {
        return array_values(array_filter(
            $itineraries,
            fn (Itinerary $i): bool => null === $this->rejectionReason($i),
        ));
    }

    public function rejectionReason(Itinerary $itinerary): ?string
    {
        foreach ($this->rules as $rule) {
            if (!$rule->isSatisfiedBy($itinerary)) {
                return $rule->reasonFor($itinerary);
            }
        }

        return null;
    }
}
