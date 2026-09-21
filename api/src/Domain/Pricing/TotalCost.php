<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

use App\Domain\Flight\Money;

final readonly class TotalCost
{
    public function __construct(
        public Money $basePrice,
        public Money $trainCost,
        public bool $indicative,
        public bool $trainCostKnown,
        /**
         * Of de transfer Helsinki–Turku in dit totaal zit.
         *
         * Los van $trainCostKnown, want de twee betekenen echt iets anders:
         * $trainCostKnown = false betekent "we weten de prijs niet" en haalt de
         * optie uit de sortering. $trainCostIncluded = false betekent "de prijs is
         * bewust niet meegeteld" — de optie blijft gewoon meedoen, maar het totaal
         * is aantoonbaar onvolledig en de UI moet dat melden. Zonder dit veld zou
         * een treinprijs van € 0,00 niet te onderscheiden zijn van "echt gratis",
         * en dat is precies de onzichtbare nul die spec §5 verbiedt.
         */
        public bool $trainCostIncluded,
    ) {
    }

    public function total(): Money
    {
        return $this->basePrice->add($this->trainCost);
    }
}
