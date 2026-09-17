<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Source;

use App\Domain\Port\FlightPriceSource;
use App\Infrastructure\Source\IgnavFlightSource;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Meet door de échte container, niet langs de configuratie heen.
 *
 * Een eerdere fout van factor 100 in een parameter bleef onzichtbaar omdat zowel
 * de tests als een handmatige controle de waarde zelf meegaven. Deze test vraagt
 * de container wat hij werkelijk bedraadt.
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
