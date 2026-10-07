<?php

declare(strict_types=1);

namespace App\Tests\Double;

use App\Domain\Booking\BookingLink;
use App\Domain\Booking\BookingLinkUnavailable;
use App\Domain\Port\BookingLinkSource;

final class FixedBookingLinkSource implements BookingLinkSource
{
    /** @var list<string> */
    public array $calls = [];

    /** @param BookingLink|null $link null = the source is unreachable */
    public function __construct(private readonly ?BookingLink $link)
    {
    }

    public static function unavailable(): self
    {
        return new self(null);
    }

    public function linkFor(string $bookingRef): BookingLink
    {
        $this->calls[] = $bookingRef;

        return $this->link ?? throw new BookingLinkUnavailable('Test double: source unreachable.');
    }
}
