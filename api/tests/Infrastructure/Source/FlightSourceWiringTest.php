<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Source;

use App\Domain\Port\FlightPriceSource;
use App\Infrastructure\Source\IgnavFlightSource;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Measure through the real container, not bypassing the configuration.
 *
 * An earlier error of factor 100 in a parameter remained invisible because both
 * the tests and a manual check provided the value themselves. This test asks
 * the container what it actually wires.
 */
final class FlightSourceWiringTest extends KernelTestCase
{
    public function test_the_container_wires_ignav_as_the_flight_price_source(): void
    {
        self::bootKernel();

        $source = self::getContainer()->get(FlightPriceSource::class);

        self::assertInstanceOf(IgnavFlightSource::class, $source);
    }
}
