<?php

declare(strict_types=1);

namespace App\Domain\Port;

use App\Domain\Flight\Money;

interface TrainPriceSource
{
    /**
     * Cheapest VR price Helsinki ⇄ Turku on this date.
     *
     * Returns null if the price is unknown. That covers all cases where the
     * implementation cannot determine a usable price: an empty scrape yield,
     * unreadable or missing price fields, a non-2xx response (including
     * quota exhaustion), a transport error (timeout, connection refused) and
     * unreadable JSON. The caller thus needs to catch no exception;
     * null is the complete signal for "price unknown".
     */
    public function priceFor(\DateTimeImmutable $date): ?Money;
}
