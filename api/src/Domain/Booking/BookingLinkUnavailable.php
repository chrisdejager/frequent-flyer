<?php

declare(strict_types=1);

namespace App\Domain\Booking;

/**
 * De bron kon niet gevraagd worden of gaf een onbruikbaar antwoord — iets anders
 * dan "er is geen directe link".
 */
final class BookingLinkUnavailable extends \RuntimeException
{
}
