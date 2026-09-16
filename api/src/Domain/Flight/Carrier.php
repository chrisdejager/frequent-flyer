<?php

declare(strict_types=1);

namespace App\Domain\Flight;

enum Carrier: string
{
    case Finnair = 'AY';
    case Sas = 'SK';
    case Klm = 'KL';

    public function label(): string
    {
        return match ($this) {
            self::Finnair => 'Finnair',
            self::Sas => 'SAS',
            self::Klm => 'KLM',
        };
    }
}
