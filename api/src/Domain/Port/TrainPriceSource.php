<?php

declare(strict_types=1);

namespace App\Domain\Port;

use App\Domain\Flight\Money;

interface TrainPriceSource
{
    /** Goedkoopste VR-prijs Helsinki ⇄ Turku op deze datum. */
    public function priceFor(\DateTimeImmutable $date): Money;
}
