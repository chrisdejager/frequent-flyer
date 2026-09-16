<?php

declare(strict_types=1);

namespace App\Domain\Flight;

final readonly class Money
{
    private const MAX_EUROS = 9_000_000_000_000_000.0; // 9×10^15, veilig onder PHP_INT_MAX/100

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
        if (!is_finite($euros)) {
            throw new \InvalidArgumentException(
                sprintf('Money::fromEuros() ontving een niet-eindige waarde (%s).', $euros),
            );
        }

        if (abs($euros) > self::MAX_EUROS) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Money::fromEuros() ontving een bedrag dat te groot om in centen uit te drukken (%.2e euro).',
                    $euros,
                ),
            );
        }

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
