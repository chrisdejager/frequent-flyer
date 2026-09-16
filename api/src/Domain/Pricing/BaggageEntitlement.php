<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

final readonly class BaggageEntitlement
{
    public function __construct(
        public bool $cabinBagIncluded,
        public bool $checkedBagIncluded,
    ) {
    }

    public function coversHandLuggage(): bool
    {
        // Handbagage is gedekt als die gratis mee mag, óf als ruimbagage gratis is:
        // in dat laatste geval checken we de handbagage gewoon in.
        return $this->cabinBagIncluded || $this->checkedBagIncluded;
    }
}
