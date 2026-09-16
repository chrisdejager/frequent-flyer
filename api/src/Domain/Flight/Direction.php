<?php

declare(strict_types=1);

namespace App\Domain\Flight;

enum Direction: string
{
    case NlToFi = 'nl-fi';
    case FiToNl = 'fi-nl';

    public function isOutbound(): bool
    {
        return self::NlToFi === $this;
    }
}
