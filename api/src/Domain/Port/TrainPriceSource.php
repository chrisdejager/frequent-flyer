<?php

declare(strict_types=1);

namespace App\Domain\Port;

use App\Domain\Flight\Money;

interface TrainPriceSource
{
    /**
     * Goedkoopste VR-prijs Helsinki ⇄ Turku op deze datum.
     *
     * Geeft null als er geen bruikbare prijs in het antwoord zit — bijvoorbeeld
     * een lege lijst van treinen, of een prijsveld met een naam die de scraper
     * niet herkent.
     *
     * Let op: een transportfout of een non-2xx-antwoord levert geen null op,
     * maar geeft een exception. Alleen het ontbreken van bruikbare prijsdata
     * in een verder geldig antwoord resulteert in null.
     */
    public function priceFor(\DateTimeImmutable $date): ?Money;
}
