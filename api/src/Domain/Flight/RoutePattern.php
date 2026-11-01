<?php

declare(strict_types=1);

namespace App\Domain\Flight;

/**
 * The two shapes of the demo route.
 *
 * Helsinki: Helsinki is an endpoint of the ticket (AMS ⇄ HEL, direct only).
 * ThroughTicket: one ticket to or from Turku with one connection.
 */
enum RoutePattern: string
{
    case Helsinki = 'helsinki';
    case ThroughTicket = 'through';
}
