<?php

declare(strict_types=1);

namespace App\Domain\Port;

use App\Domain\Flight\Airport;
use App\Domain\Flight\Itinerary;

interface FlightPriceSource
{
    /**
     * @return Itinerary[] ongefilterd; de regels worden later toegepast
     */
    public function search(Airport $from, Airport $to, \DateTimeImmutable $date): array;
}
