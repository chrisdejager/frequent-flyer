<?php

declare(strict_types=1);

namespace App\Domain\Flight;

enum RoutePattern: string
{
    case ViaHelsinkiWithTrain = 'via_hel_train';
    case ThroughTicket = 'through';
}
