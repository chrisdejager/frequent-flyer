<?php

declare(strict_types=1);

namespace App\Tests\Domain\Pricing;

use App\Domain\Flight\Carrier;
use App\Domain\Pricing\BaggagePolicy;
use App\Domain\Pricing\FareUpgradeRates;
use App\Domain\Traveller\LoyaltyStatus;
use App\Domain\Traveller\Traveller;
use PHPUnit\Framework\TestCase;

final class BaggagePolicyTest extends TestCase
{
    private BaggagePolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new BaggagePolicy(
            FareUpgradeRates::fromConfig(['AY' => 35, 'KL' => 40]),
        );
    }

    public function test_finnair_silver_gets_cabin_baggage_for_free(): void
    {
        $entitlement = $this->policy->entitlementFor(Carrier::Finnair, LoyaltyStatus::Silver);

        self::assertTrue($entitlement->cabinBagIncluded);
        self::assertTrue($this->policy->costFor(Carrier::Finnair, LoyaltyStatus::Silver)->isZero());
    }

    public function test_finnair_without_status_pays_the_upgrade(): void
    {
        $entitlement = $this->policy->entitlementFor(Carrier::Finnair, LoyaltyStatus::None);

        self::assertFalse($entitlement->cabinBagIncluded);
        self::assertSame(3500, $this->policy->costFor(Carrier::Finnair, LoyaltyStatus::None)->cents);
    }

    public function test_sas_silver_pays_nothing_because_hand_luggage_goes_as_checked(): void
    {
        $entitlement = $this->policy->entitlementFor(Carrier::Sas, LoyaltyStatus::Silver);

        self::assertFalse($entitlement->cabinBagIncluded, 'SAS geeft geen gratis handbagage');
        self::assertTrue($entitlement->checkedBagIncluded, 'SAS Silver geeft wel gratis ruimbagage');
        self::assertTrue($this->policy->costFor(Carrier::Sas, LoyaltyStatus::Silver)->isZero());
    }

    public function test_klm_always_pays_the_upgrade(): void
    {
        self::assertSame(4000, $this->policy->costFor(Carrier::Klm, LoyaltyStatus::None)->cents);
    }

    public function test_the_same_finnair_flight_costs_the_partner_more_than_chris(): void
    {
        $chris = $this->policy->costFor(Carrier::Finnair, Traveller::chris()->statusFor(Carrier::Finnair));
        $partner = $this->policy->costFor(Carrier::Finnair, Traveller::partner()->statusFor(Carrier::Finnair));

        self::assertTrue($chris->isZero());
        self::assertSame(3500, $partner->cents);
    }

    public function test_sas_costs_them_the_same(): void
    {
        $chris = $this->policy->costFor(Carrier::Sas, Traveller::chris()->statusFor(Carrier::Sas));
        $partner = $this->policy->costFor(Carrier::Sas, Traveller::partner()->statusFor(Carrier::Sas));

        self::assertSame($chris->cents, $partner->cents);
        self::assertTrue($chris->isZero());
    }
}
