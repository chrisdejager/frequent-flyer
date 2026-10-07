<?php

declare(strict_types=1);

namespace App\Domain\Port;

use App\Domain\Booking\BookingLink;
use App\Domain\Booking\BookingLinkUnavailable;

interface BookingLinkSource
{
    /**
     * Booking link for a previously found flight, designated with the reference
     * that the flight source provided as Itinerary::$bookingRef.
     *
     * @throws BookingLinkUnavailable on failure, unreadable or unexpected response
     */
    public function linkFor(string $bookingRef): BookingLink;
}
