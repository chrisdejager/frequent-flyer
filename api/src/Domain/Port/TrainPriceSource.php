<?php

declare(strict_types=1);

namespace App\Domain\Port;

use App\Domain\Flight\Money;

interface TrainPriceSource
{
    /**
     * Goedkoopste VR-prijs Helsinki ⇄ Turku op deze datum.
     * Geeft null als er geen bruikbare prijs beschikbaar is
     * (lege scraperuitkomst, quota overschreden, etc.).
     */
    public function priceFor(\DateTimeImmutable $date): ?Money;
}
