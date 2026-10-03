<?php

declare(strict_types=1);

namespace App\Domain\Port;

use App\Domain\Booking\BookingLink;
use App\Domain\Booking\BookingLinkUnavailable;

interface BookingLinkSource
{
    /**
     * Boekingslink voor een eerder gevonden vlucht, aangewezen met de verwijzing
     * die de vluchtbron als Itinerary::$bookingRef meegaf.
     *
     * @throws BookingLinkUnavailable bij storing, onleesbaar of onverwacht antwoord
     */
    public function linkFor(string $bookingRef): BookingLink;
}
