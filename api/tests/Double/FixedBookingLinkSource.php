<?php

declare(strict_types=1);

namespace App\Tests\Double;

use App\Domain\Booking\BookingLink;
use App\Domain\Port\BookingLinkSource;

final class FixedBookingLinkSource implements BookingLinkSource
{
    /** @var list<string> */
    public array $calls = [];

    public function __construct(private readonly BookingLink $link)
    {
    }

    public function linkFor(string $bookingRef): BookingLink
    {
        $this->calls[] = $bookingRef;

        return $this->link;
    }
}
