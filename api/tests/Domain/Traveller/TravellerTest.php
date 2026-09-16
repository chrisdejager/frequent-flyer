<?php

declare(strict_types=1);

namespace App\Tests\Domain\Traveller;

use App\Domain\Flight\Carrier;
use App\Domain\Traveller\LoyaltyStatus;
use App\Domain\Traveller\Traveller;
use PHPUnit\Framework\TestCase;

final class TravellerTest extends TestCase
{
    public function test_chris_has_silver_at_both_finnair_and_sas(): void
    {
        $chris = Traveller::chris();

        self::assertSame(LoyaltyStatus::Silver, $chris->statusFor(Carrier::Finnair));
        self::assertSame(LoyaltyStatus::Silver, $chris->statusFor(Carrier::Sas));
    }

    public function test_partner_has_silver_at_sas_but_nothing_at_finnair(): void
    {
        $partner = Traveller::partner();

        self::assertSame(LoyaltyStatus::Silver, $partner->statusFor(Carrier::Sas));
        self::assertSame(LoyaltyStatus::None, $partner->statusFor(Carrier::Finnair));
    }

    public function test_nobody_has_status_at_klm(): void
    {
        self::assertSame(LoyaltyStatus::None, Traveller::chris()->statusFor(Carrier::Klm));
        self::assertSame(LoyaltyStatus::None, Traveller::partner()->statusFor(Carrier::Klm));
    }

    public function test_lookup_by_id(): void
    {
        self::assertSame('chris', Traveller::byId('chris')->id);
        self::assertSame('partner', Traveller::byId('partner')->id);
    }

    public function test_unknown_id_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Traveller::byId('iemand-anders');
    }
}
