<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Source;

use App\Domain\Port\BookingLinkSource;
use App\Infrastructure\Source\IgnavBookingLinkSource;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Meet door de échte container, niet langs de configuratie heen — zie
 * FlightSourceWiringTest.
 */
final class BookingLinkSourceWiringTest extends KernelTestCase
{
    public function test_the_container_wires_ignav_as_booking_link_source(): void
    {
        self::bootKernel();

        $source = self::getContainer()->get(BookingLinkSource::class);

        self::assertInstanceOf(IgnavBookingLinkSource::class, $source);
    }
}
