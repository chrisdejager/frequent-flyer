<?php

declare(strict_types=1);

namespace App\Domain\Flight;

final readonly class Money
{
    private function __construct(public int $cents)
    {
        if ($cents < 0) {
            throw new \InvalidArgumentException('Bedrag mag niet negatief zijn.');
        }
    }

    public static function fromCents(int $cents): self
    {
        return new self($cents);
    }

    public static function fromEuros(float $euros): self
    {
        return new self((int) round($euros * 100));
    }

    public static function zero(): self
    {
        return new self(0);
    }

    public function add(self $other): self
    {
        return new self($this->cents + $other->cents);
    }

    public function isLessThan(self $other): bool
    {
        return $this->cents < $other->cents;
    }

    public function isZero(): bool
    {
        return 0 === $this->cents;
    }

    public function format(): string
    {
        return sprintf('€ %s,%02d', intdiv($this->cents, 100), $this->cents % 100);
    }
}
