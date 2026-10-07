<?php

declare(strict_types=1);

namespace App\Tests\Domain\Flight;

use App\Domain\Flight\Money;
use PHPUnit\Framework\TestCase;

final class MoneyTest extends TestCase
{
    public function test_euros_become_whole_cents(): void
    {
        self::assertSame(13400, Money::fromEuros(134.00)->cents);
        self::assertSame(3550, Money::fromEuros(35.50)->cents);
    }

    public function test_rounding_does_not_drift(): void
    {
        self::assertSame(1010, Money::fromEuros(10.1)->cents);
    }

    public function test_addition(): void
    {
        $sum = Money::fromEuros(134.00)->add(Money::fromEuros(12.50));

        self::assertSame(14650, $sum->cents);
    }

    public function test_comparison(): void
    {
        self::assertTrue(Money::fromEuros(80)->isLessThan(Money::fromEuros(134)));
        self::assertFalse(Money::fromEuros(134)->isLessThan(Money::fromEuros(80)));
    }

    public function test_zero(): void
    {
        self::assertTrue(Money::zero()->isZero());
        self::assertFalse(Money::fromEuros(1)->isZero());
    }

    public function test_dutch_formatting(): void
    {
        self::assertSame('€ 134,00', Money::fromEuros(134)->format());
        self::assertSame('€ 9,05', Money::fromEuros(9.05)->format());
    }

    public function test_negative_amounts_are_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Money::fromCents(-1);
    }

    public function test_inf_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('non-finite value');

        Money::fromEuros(INF);
    }

    public function test_negative_inf_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('non-finite value');

        Money::fromEuros(-INF);
    }

    public function test_nan_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('non-finite value');

        Money::fromEuros(NAN);
    }

    public function test_overflow_gives_honest_error_message(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/too large to express in cents/');

        Money::fromEuros(1e17);
    }

    public function test_max_euros_boundary_succeeds(): void
    {
        // 9×10^15 is the limit itself — this must succeed.
        $money = Money::fromEuros(9e15);

        self::assertSame((int) round(9e15 * 100), $money->cents);
    }

    public function test_just_above_max_euros_throws(): void
    {
        // One step above the limit (9×10^15 + 1) must give an overflow exception,
        // so a careless adjustment of MAX_EUROS does not go unnoticed.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/too large to express in cents/');

        Money::fromEuros(9e15 + 1);
    }
}
