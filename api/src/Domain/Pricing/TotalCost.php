<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

use App\Domain\Flight\Money;

final readonly class TotalCost
{
    public function __construct(
        public Money $basePrice,
        public Money $baggageCost,
        public Money $trainCost,
        public bool $indicative,
        public bool $trainCostKnown,
    ) {
    }

    public function total(): Money
    {
        return $this->basePrice->add($this->baggageCost)->add($this->trainCost);
    }
}
