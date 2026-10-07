<?php

declare(strict_types=1);

namespace App\Tests\Domain\Booking;

use App\Domain\Booking\BookingLink;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BookingLinkTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function unsafeUrls(): iterable
    {
        yield 'http (measured with SAS)' => ['http://www.flysas.com/nl/nl/'];
        yield 'javascript' => ['javascript:alert(1)'];
        yield 'data' => ['data:text/html,<script>alert(1)</script>'];
        yield 'protocol-relative' => ['//evil.example/'];
    }

    #[DataProvider('unsafeUrls')]
    public function test_a_direct_link_must_be_https(string $url): void
    {
        $this->expectException(\InvalidArgumentException::class);

        BookingLink::direct($url, 'X');
    }

    public function test_no_direct_link_has_neither_url_nor_provider(): void
    {
        $link = BookingLink::noDirectLink();

        self::assertFalse($link->isDirect());
        self::assertNull($link->url);
        self::assertNull($link->provider);
    }
}
