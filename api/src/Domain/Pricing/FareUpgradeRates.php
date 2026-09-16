<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

use App\Domain\Flight\Carrier;
use App\Domain\Flight\Money;

final readonly class FareUpgradeRates
{
    /**
     * Maatschappijen waarvoor een tarief verplicht geconfigureerd moet zijn.
     * SAS staat hier niet bij: daar gaat handbagage als (gratis) ruimbagage mee.
     */
    private const REQUIRED = [Carrier::Finnair, Carrier::Klm];

    /** Maatschappijen die dynamisch prijzen; hun tarief is een schatting. */
    private const ESTIMATED = [Carrier::Klm];

    /**
     * @param array<string, Money> $rates
     */
    private function __construct(private array $rates)
    {
    }

    /**
     * @param array<string, int|float|null> $raw tarieven in euro's, gesleuteld op carriercode
     */
    public static function fromConfig(array $raw): self
    {
        $rates = [];

        foreach (self::REQUIRED as $carrier) {
            $value = $raw[$carrier->value] ?? null;

            if (!is_int($value) && !is_float($value)) {
                throw MissingFareUpgradeRate::for($carrier->value);
            }

            $rates[$carrier->value] = Money::fromEuros((float) $value);
        }

        return new self($rates);
    }

    public function for(Carrier $carrier): Money
    {
        return $this->rates[$carrier->value] ?? Money::zero();
    }

    public function isEstimate(Carrier $carrier): bool
    {
        return in_array($carrier, self::ESTIMATED, true);
    }
}
