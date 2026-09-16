<?php

declare(strict_types=1);

namespace App\Tests\Double;

use App\Domain\Flight\Money;
use App\Domain\Port\TrainPriceSource;

final class FixedTrainPriceSource implements TrainPriceSource
{
    public int $callCount = 0;

    private function __construct(private readonly ?Money $price)
    {
    }

    public static function withPrice(Money $price): self
    {
        return new self($price);
    }

    public static function withUnknownPrice(): self
    {
        return new self(null);
    }

    public function priceFor(\DateTimeImmutable $date): ?Money
    {
        ++$this->callCount;

        return $this->price;
    }
}
