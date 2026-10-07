<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Flight\Itinerary;
use App\Domain\Port\PriceHistory;

/**
 * Phase 1 does not yet preserve price history. Phase 2 replaces this class
 * with an SQLite implementation without changing the use case.
 */
final class NullPriceHistory implements PriceHistory
{
    public function record(Itinerary $itinerary, \DateTimeImmutable $observedAt): void
    {
    }
}
