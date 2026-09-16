<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

final class MissingFareUpgradeRate extends \RuntimeException
{
    public static function for(string $carrierCode): self
    {
        return new self(sprintf(
            'Geen fare_upgrade-tarief geconfigureerd voor %s. Vul dit in; doorrekenen met €0 '
            .'zou Light-tarieven structureel te goedkoop laten lijken.',
            $carrierCode,
        ));
    }
}
