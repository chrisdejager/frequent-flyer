<?php

declare(strict_types=1);

namespace App\Tests\Domain\Pricing;

use App\Domain\Flight\Carrier;
use App\Domain\Pricing\FareUpgradeRates;
use App\Domain\Pricing\MissingFareUpgradeRate;
use PHPUnit\Framework\TestCase;

final class FareUpgradeRatesTest extends TestCase
{
    public function test_configured_rates_are_returned_as_money(): void
    {
        $rates = FareUpgradeRates::fromConfig(['AY' => 35, 'KL' => 40.50]);

        self::assertSame(3500, $rates->for(Carrier::Finnair)->cents);
        self::assertSame(4050, $rates->for(Carrier::Klm)->cents);
    }

    public function test_a_missing_key_throws(): void
    {
        $this->expectException(MissingFareUpgradeRate::class);
        $this->expectExceptionMessage('AY');

        FareUpgradeRates::fromConfig(['KL' => 40]);
    }

    public function test_a_null_value_throws_rather_than_becoming_zero(): void
    {
        $this->expectException(MissingFareUpgradeRate::class);

        FareUpgradeRates::fromConfig(['AY' => null, 'KL' => 40]);
    }

    public function test_klm_is_marked_as_an_estimate_and_finnair_is_not(): void
    {
        $rates = FareUpgradeRates::fromConfig(['AY' => 35, 'KL' => 40]);

        self::assertFalse($rates->isEstimate(Carrier::Finnair));
        self::assertTrue($rates->isEstimate(Carrier::Klm));
    }

    public function test_sas_needs_no_rate_because_baggage_is_never_bought_there(): void
    {
        $rates = FareUpgradeRates::fromConfig(['AY' => 35, 'KL' => 40]);

        self::assertTrue($rates->for(Carrier::Sas)->isZero());
    }
}
