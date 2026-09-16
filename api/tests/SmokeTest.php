<?php

declare(strict_types=1);

namespace App\Tests;

use PHPUnit\Framework\TestCase;

final class SmokeTest extends TestCase
{
    public function test_the_test_runner_works(): void
    {
        self::assertSame('8.4', substr(PHP_VERSION, 0, 3));
    }
}
