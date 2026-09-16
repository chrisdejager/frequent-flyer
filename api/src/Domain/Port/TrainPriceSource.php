<?php

declare(strict_types=1);

namespace App\Domain\Port;

use App\Domain\Flight\Money;

interface TrainPriceSource
{
    /**
     * Goedkoopste VR-prijs Helsinki ⇄ Turku op deze datum.
     *
     * Geeft null als de prijs onbekend is. Dat omvat alle gevallen waarbij de
     * implementatie geen bruikbare prijs kan vaststellen: een lege scrapopbrengst,
     * onleesbare of ontbrekende prijsvelden, een non-2xx-antwoord (inclusief
     * quota-uitputting), een transportfout (time-out, verbindingsweigering) en
     * onleesbare JSON. De aanroeper hoeft dus geen exception op te vangen;
     * null is de volledige signalering van "prijs onbekend".
     */
    public function priceFor(\DateTimeImmutable $date): ?Money;
}
