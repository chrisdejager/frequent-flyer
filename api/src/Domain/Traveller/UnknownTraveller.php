<?php

declare(strict_types=1);

namespace App\Domain\Traveller;

final class UnknownTraveller extends \InvalidArgumentException
{
    public static function withId(string $id): self
    {
        return new self(sprintf('Onbekende reiziger "%s".', $id));
    }
}
