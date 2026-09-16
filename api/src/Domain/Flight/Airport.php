<?php

declare(strict_types=1);

namespace App\Domain\Flight;

enum Airport: string
{
    case Ams = 'AMS';
    case Hel = 'HEL';
    case Tku = 'TKU';
    case Arn = 'ARN';
    case Cph = 'CPH';
}
