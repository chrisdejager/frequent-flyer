<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

use App\Domain\Flight\Money;

final readonly class TotalCost
{
    public function __construct(
        public Money $basePrice,
        public Money $trainCost,
        public bool $indicative,
        public bool $trainCostKnown,
        /**
         * Whether the Helsinki–Turku transfer is in this total.
         *
         * Separate from $trainCostKnown, because the two mean really different things:
         * $trainCostKnown = false means "we don't know the price" and removes the
         * option from sorting. $trainCostIncluded = false means "the price is
         * deliberately not counted" — the option continues to participate, but the total
         * is demonstrably incomplete and the UI must report it. Without this field,
         * a train price of € 0.00 could not be distinguished from "really free",
         * and that's exactly the invisible zero that spec §5 forbids.
         */
        public bool $trainCostIncluded,
    ) {
    }

    public function total(): Money
    {
        return $this->basePrice->add($this->trainCost);
    }
}
