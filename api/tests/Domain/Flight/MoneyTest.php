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
        $this->expectExceptionMessage('niet-eindige waarde');

        Money::fromEuros(INF);
    }

    public function test_negative_inf_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('niet-eindige waarde');

        Money::fromEuros(-INF);
    }

    public function test_nan_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('niet-eindige waarde');

        Money::fromEuros(NAN);
    }

    public function test_overflow_gives_honest_error_message(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/te groot om in centen/');

        Money::fromEuros(1e17);
    }
}
