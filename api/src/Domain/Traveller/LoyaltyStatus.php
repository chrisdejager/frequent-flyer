<?php

declare(strict_types=1);

namespace App\Domain\Traveller;

enum LoyaltyStatus: string
{
    case None = 'none';
    case Silver = 'silver';
}
