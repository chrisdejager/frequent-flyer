<?php

declare(strict_types=1);

namespace App\Tests\Double;

use App\Domain\Flight\Money;
use App\Domain\Port\TrainPriceSource;

final class FixedTrainPriceSource implements TrainPriceSource
{
    public int $callCount = 0;

    public function __construct(private readonly Money $price)
    {
    }

    public function priceFor(\DateTimeImmutable $date): Money
    {
        ++$this->callCount;

        return $this->price;
    }
}
