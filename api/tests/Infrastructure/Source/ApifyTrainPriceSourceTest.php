<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Source;

use App\Infrastructure\Source\ApifyTrainPriceSource;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class ApifyTrainPriceSourceTest extends TestCase
{
    public function test_it_returns_the_cheapest_train_of_the_day(): void
    {
        $source = new ApifyTrainPriceSource(
            new MockHttpClient(new MockResponse(self::fixture())),
            new ArrayAdapter(),
            'test-token',
        );

        $price = $source->priceFor(new \DateTimeImmutable('2026-10-29'));

        self::assertSame(2250, $price->cents);
    }

    public function test_a_second_call_for_the_same_date_does_not_hit_apify_again(): void
    {
        $calls = 0;
        $client = new MockHttpClient(function () use (&$calls) {
            ++$calls;

            return new MockResponse(self::fixture());
        });

        $source = new ApifyTrainPriceSource($client, new ArrayAdapter(), 'test-token');

        $source->priceFor(new \DateTimeImmutable('2026-10-29'));
        $source->priceFor(new \DateTimeImmutable('2026-10-29'));

        self::assertSame(1, $calls, 'het Apify-tegoed is te klein om dit ongecached te doen');
    }

    public function test_different_dates_are_cached_separately(): void
    {
        $calls = 0;
        $client = new MockHttpClient(function () use (&$calls) {
            ++$calls;

            return new MockResponse(self::fixture());
        });

        $source = new ApifyTrainPriceSource($client, new ArrayAdapter(), 'test-token');

        $source->priceFor(new \DateTimeImmutable('2026-10-25'));
        $source->priceFor(new \DateTimeImmutable('2026-10-29'));

        self::assertSame(2, $calls);
    }

    public function test_an_empty_result_yields_zero_rather_than_crashing(): void
    {
        $source = new ApifyTrainPriceSource(
            new MockHttpClient(new MockResponse('[]')),
            new ArrayAdapter(),
            'test-token',
        );

        self::assertTrue($source->priceFor(new \DateTimeImmutable('2026-10-29'))->isZero());
    }

    private static function fixture(): string
    {
        return file_get_contents(__DIR__.'/../../Fixtures/apify-vr-hel-tku.json');
    }
}
