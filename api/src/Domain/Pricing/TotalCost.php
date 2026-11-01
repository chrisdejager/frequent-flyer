<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

use App\Domain\Flight\Money;

final readonly class TotalCost
{
    public function __construct(
        public Money $basePrice,
    ) {
    }

    /**
     * Today the total is the flight price alone: baggage is deliberately left
     * out (ADR-0005). A new cost line is added here, not in the callers.
     */
    public function total(): Money
    {
        return $this->basePrice;
    }
}
