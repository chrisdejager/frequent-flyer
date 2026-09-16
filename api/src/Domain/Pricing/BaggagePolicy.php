<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

use App\Domain\Flight\Carrier;
use App\Domain\Flight\Money;
use App\Domain\Traveller\LoyaltyStatus;

final readonly class BaggagePolicy
{
    public function __construct(private FareUpgradeRates $rates)
    {
    }

    public function entitlementFor(Carrier $carrier, LoyaltyStatus $status): BaggageEntitlement
    {
        $silver = LoyaltyStatus::Silver === $status;

        return match ($carrier) {
            // Finnair Plus Silver geeft handbagage op een Light-tarief.
            Carrier::Finnair => new BaggageEntitlement($silver, false),
            // SAS EuroBonus Silver geeft ruimbagage, maar géén handbagage.
            Carrier::Sas => new BaggageEntitlement(false, $silver),
            // Bij KLM heeft niemand status.
            Carrier::Klm => new BaggageEntitlement(false, false),
        };
    }

    public function costFor(Carrier $carrier, LoyaltyStatus $status): Money
    {
        if ($this->entitlementFor($carrier, $status)->coversHandLuggage()) {
            return Money::zero();
        }

        return $this->rates->for($carrier);
    }
}
