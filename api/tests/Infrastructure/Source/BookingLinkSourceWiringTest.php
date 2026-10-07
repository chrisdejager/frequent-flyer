<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Source;

use App\Domain\Port\BookingLinkSource;
use App\Infrastructure\Source\IgnavBookingLinkSource;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Measure through the real container, not bypassing the configuration — see
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
    public function test_the_wired_source_caches_so_a_second_click_costs_no_call(): void
    {
        self::bootKernel();

        $calls = 0;
        $body = (string) file_get_contents(__DIR__.'/fixtures/ignav-booking-links.json');
        self::getContainer()->set('http_client', new MockHttpClient(function () use (&$calls, $body): MockResponse {
            ++$calls;

            return new MockResponse($body);
        }));

        // cache.app is on the file system in tests and thus survives a run:
        // a fixed ref would hit on the second attempt already at the first request.
        $ref = bin2hex(random_bytes(16));
        $source = self::getContainer()->get(BookingLinkSource::class);

        $source->linkFor($ref);
        $second = $source->linkFor($ref);

        self::assertSame(1, $calls, 'The second request must come from cache.');
        self::assertSame('Finnair', $second->provider);
    }
}
