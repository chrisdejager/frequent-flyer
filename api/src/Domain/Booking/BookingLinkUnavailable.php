<?php

declare(strict_types=1);

namespace App\Domain\Booking;

/**
 * The source could not be queried or returned an unusable response — something
 * other than "there is no direct link".
 */
final class BookingLinkUnavailable extends \RuntimeException
{
}
