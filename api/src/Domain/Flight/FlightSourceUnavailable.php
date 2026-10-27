<?php

declare(strict_types=1);

namespace App\Domain\Flight;

/**
 * The flight source cannot be queried at all, e.g. because it is not
 * configured — something other than "no flights found".
 */
final class FlightSourceUnavailable extends \RuntimeException
{
}
