<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Flight\Itinerary;
use App\Domain\Port\PriceHistory;

/**
 * Fase 1 bewaart nog geen prijsgeschiedenis. Fase 2 vervangt deze klasse
 * door een SQLite-implementatie zonder dat de use case wijzigt.
 */
final class NullPriceHistory implements PriceHistory
{
    public function record(Itinerary $itinerary, \DateTimeImmutable $observedAt): void
    {
    }
}
