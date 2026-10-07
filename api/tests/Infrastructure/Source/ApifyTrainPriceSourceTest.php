<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Source;

use App\Infrastructure\Source\ApifyTrainPriceSource;
use App\Tests\Double\SpyCacheItemPool;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\Exception\TransportException;
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

        self::assertSame(1, $calls, 'Apify credits are too small to do this uncached');
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

    public function test_an_empty_result_yields_null(): void
    {
        $source = new ApifyTrainPriceSource(
            new MockHttpClient(new MockResponse('[]')),
            new ArrayAdapter(),
            'test-token',
        );

        self::assertNull($source->priceFor(new \DateTimeImmutable('2026-10-29')));
    }

    public function test_a_zero_euro_price_is_not_filtered_out(): void
    {
        $json = json_encode([
            ['priceEur' => 0.0, 'departureTime' => '2026-10-29T08:00:00', 'trainType' => 'IC'],
            ['priceEur' => 22.5, 'departureTime' => '2026-10-29T17:00:00', 'trainType' => 'S'],
        ]);

        $source = new ApifyTrainPriceSource(
            new MockHttpClient(new MockResponse($json)),
            new ArrayAdapter(),
            'test-token',
        );

        $price = $source->priceFor(new \DateTimeImmutable('2026-10-29'));

        self::assertSame(0, $price->cents, 'a price of €0 is a valid (free) journey and must not be filtered out');

        // Second call: cache return must not convert a real €0 price to null.
        // Living mutant: CACHE_NULL_SENTINEL = 0 (int) would let the sentinel check pass
        // for a cached 0-cent price, causing the second call to return null.
        $second = $source->priceFor(new \DateTimeImmutable('2026-10-29'));

        self::assertNotNull($second, 'cache-hit for €0: must not be interpreted as null-sentinel');
        self::assertSame(0, $second->cents, 'cache-hit for €0: must return the same 0 cent price');
    }

    public function test_a_null_result_is_cached_for_15_minutes(): void
    {
        $calls = 0;
        $client = new MockHttpClient(function () use (&$calls) {
            ++$calls;

            return new MockResponse('[]');
        });

        $source = new ApifyTrainPriceSource($client, new ArrayAdapter(), 'test-token');

        $first  = $source->priceFor(new \DateTimeImmutable('2026-10-29'));
        $second = $source->priceFor(new \DateTimeImmutable('2026-10-29'));

        self::assertNull($first);
        self::assertNull($second);
        self::assertSame(1, $calls, 'null result must be cached (15 min) so each click doesn\'t cost an Apify run');
    }

    public function test_a_malformed_response_is_cached_for_short_ttl(): void
    {
        $spy = new SpyCacheItemPool();
        $source = new ApifyTrainPriceSource(
            new MockHttpClient(new MockResponse('this is not json!')),
            $spy,
            'test-token',
        );

        $result = $source->priceFor(new \DateTimeImmutable('2026-10-29'));

        self::assertNull($result, 'unreadable JSON must return null');
        self::assertArrayHasKey('vr.hel-tku.2026-10-29', $spy->recordedTtls, 'unreadable JSON must also be cached');
        self::assertSame(900, $spy->recordedTtls['vr.hel-tku.2026-10-29'], 'short TTL of 900 s expected');
    }

    public function test_http_error_returns_null_and_is_cached_for_short_ttl(): void
    {
        $spy = new SpyCacheItemPool();
        $source = new ApifyTrainPriceSource(
            new MockHttpClient(new MockResponse('Server Error', ['http_code' => 500])),
            $spy,
            'test-token',
        );

        $result = $source->priceFor(new \DateTimeImmutable('2026-10-29'));

        self::assertNull($result, 'HTTP 500 must return null');
        self::assertArrayHasKey('vr.hel-tku.2026-10-29', $spy->recordedTtls);
        self::assertSame(900, $spy->recordedTtls['vr.hel-tku.2026-10-29'], 'error must be cached shortly');
    }

    public function test_quota_exceeded_returns_null_and_is_cached_for_short_ttl(): void
    {
        $spy = new SpyCacheItemPool();
        $source = new ApifyTrainPriceSource(
            new MockHttpClient(new MockResponse('Too Many Requests', ['http_code' => 429])),
            $spy,
            'test-token',
        );

        $result = $source->priceFor(new \DateTimeImmutable('2026-10-29'));

        self::assertNull($result, 'HTTP 429 (quota exhausted) must return null');
        self::assertArrayHasKey('vr.hel-tku.2026-10-29', $spy->recordedTtls);
        self::assertSame(900, $spy->recordedTtls['vr.hel-tku.2026-10-29'], 'quota exhaustion must be cached shortly');
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function nonArrayRowProvider(): array
    {
        return [
            'float (22.5)'  => [22.5],
            'broken string'  => ['kapot'],
            'null'          => [null],
            'bool true'     => [true],
        ];
    }

    /**
     * Each non-array row in the Apify response is skipped; the valid row
     * returns €22.50. Covers production crashes with 'broken' (string) and null.
     */
    #[DataProvider('nonArrayRowProvider')]
    public function test_non_array_row_is_skipped(mixed $badRow): void
    {
        $json = json_encode([$badRow, ['priceEur' => 22.5, 'departureTime' => '2026-10-29T17:00:00', 'trainType' => 'S']]);

        $source = new ApifyTrainPriceSource(
            new MockHttpClient(new MockResponse($json)),
            new ArrayAdapter(),
            'test-token',
        );

        $price = $source->priceFor(new \DateTimeImmutable('2026-10-29'));

        self::assertSame(2250, $price->cents, 'invalid row skipped, valid row returns €22.50');
    }

    public function test_transport_error_returns_null_and_is_cached_for_short_ttl(): void
    {
        $spy = new SpyCacheItemPool();
        $source = new ApifyTrainPriceSource(
            new MockHttpClient(static function (): never {
                throw new TransportException('Connection timed out');
            }),
            $spy,
            'test-token',
        );

        $result = $source->priceFor(new \DateTimeImmutable('2026-10-29'));

        self::assertNull($result, 'transport error (time-out) must return null');
        self::assertArrayHasKey('vr.hel-tku.2026-10-29', $spy->recordedTtls, 'transport error must be cached');
        self::assertSame(900, $spy->recordedTtls['vr.hel-tku.2026-10-29'], 'transport error must be cached shortly (900 s)');
    }

    public function test_a_non_numeric_price_in_the_list_is_filtered_out(): void
    {
        // "N/A" is silently read as 0.0 via (float) — a free train ride that doesn't exist.
        // The guard filters non-numeric values before the cast.
        $json = json_encode([
            ['priceEur' => 'N/A', 'departureTime' => '2026-10-29T08:00:00', 'trainType' => 'IC'],
            ['priceEur' => 22.5, 'departureTime' => '2026-10-29T17:00:00', 'trainType' => 'S'],
        ]);

        $source = new ApifyTrainPriceSource(
            new MockHttpClient(new MockResponse($json)),
            new ArrayAdapter(),
            'test-token',
        );

        $price = $source->priceFor(new \DateTimeImmutable('2026-10-29'));

        self::assertSame(2250, $price->cents, '"N/A" is skipped; the valid price of €22.50 remains');
    }

    public function test_a_negative_price_in_the_list_is_filtered_out(): void
    {
        // A negative train price throws HTTP 500 without a guard (Money::__construct throws).
        // The guard filters out negative values.
        $json = json_encode([
            ['priceEur' => -5.0, 'departureTime' => '2026-10-29T08:00:00', 'trainType' => 'IC'],
            ['priceEur' => 22.5, 'departureTime' => '2026-10-29T17:00:00', 'trainType' => 'S'],
        ]);

        $source = new ApifyTrainPriceSource(
            new MockHttpClient(new MockResponse($json)),
            new ArrayAdapter(),
            'test-token',
        );

        $price = $source->priceFor(new \DateTimeImmutable('2026-10-29'));

        self::assertSame(2250, $price->cents, 'Negative price (-5.0) is skipped; the valid price of €22.50 remains');
    }

    public function test_an_absurdly_high_price_does_not_crash_the_search(): void
    {
        // 1e17 euro is finite and non-negative, so it passed all old filters.
        // (int) round(1e17 * 100) overflows to -8446744073709551616, causing
        // Money::fromCents() to throw InvalidArgumentException: HTTP 500 on the
        // entire search. The upper bound excludes this.
        $json = json_encode([
            ['priceEur' => 1e17, 'departureTime' => '2026-10-29T08:00:00', 'trainType' => 'IC'],
        ]);

        $source = new ApifyTrainPriceSource(
            new MockHttpClient(new MockResponse($json)),
            new ArrayAdapter(),
            'test-token',
        );

        $price = $source->priceFor(new \DateTimeImmutable('2026-10-29'));

        self::assertNull($price, 'An absurdly high price is filtered out; no valid price remains');
    }

    public function test_an_absurdly_high_price_does_not_replace_a_valid_one(): void
    {
        $json = json_encode([
            ['priceEur' => 1e17, 'departureTime' => '2026-10-29T08:00:00', 'trainType' => 'IC'],
            ['priceEur' => 22.5, 'departureTime' => '2026-10-29T17:00:00', 'trainType' => 'S'],
        ]);

        $source = new ApifyTrainPriceSource(
            new MockHttpClient(new MockResponse($json)),
            new ArrayAdapter(),
            'test-token',
        );

        self::assertSame(2250, $source->priceFor(new \DateTimeImmutable('2026-10-29'))->cents);
    }

    public function test_a_null_result_is_cached_with_ttl_of_900_seconds(): void
    {
        $spy = new SpyCacheItemPool();
        $source = new ApifyTrainPriceSource(
            new MockHttpClient(new MockResponse('[]')),
            $spy,
            'test-token',
        );

        $source->priceFor(new \DateTimeImmutable('2026-10-29'));

        $key = 'vr.hel-tku.2026-10-29';
        self::assertArrayHasKey($key, $spy->recordedTtls, 'no cache item saved after a null result');
        self::assertSame(
            900,
            $spy->recordedTtls[$key],
            'null result must be cached for 15 minutes (900 s), not 24 hours — '
            .'otherwise a broken scraper is stuck for a whole day',
        );
    }

    public function test_a_valid_price_is_cached_with_ttl_of_86400_seconds(): void
    {
        $spy = new SpyCacheItemPool();
        $source = new ApifyTrainPriceSource(
            new MockHttpClient(new MockResponse(self::fixture())),
            $spy,
            'test-token',
        );

        $source->priceFor(new \DateTimeImmutable('2026-10-29'));

        $key = 'vr.hel-tku.2026-10-29';
        self::assertArrayHasKey($key, $spy->recordedTtls, 'no cache item saved after a valid price');
        self::assertSame(
            86400,
            $spy->recordedTtls[$key],
            'valid price must be cached for 24 hours (86400 s), not 15 minutes — '
            .'otherwise every click consumes Apify credits',
        );
    }

    /**
     * Regression test for the SpyCacheItem bug:
     * save() called $item->get() on a miss item, causing null to always
     * be saved regardless of what was passed via set().
     * A second call would then return null as a cache-hit, but
     * the production code interprets null-hit as CACHE_NULL_SENTINEL 'null'
     * (string) — that happened to work here, but for a valid price
     * Money::fromCents((int) null) == Money(0) would be returned instead
     * of the real price. This tests both paths.
     */
    public function test_second_call_with_empty_result_still_returns_null_and_causes_only_one_http_call(): void
    {
        $calls = 0;
        $client = new MockHttpClient(function () use (&$calls) {
            ++$calls;

            return new MockResponse('[]');
        });

        $spy    = new SpyCacheItemPool();
        $source = new ApifyTrainPriceSource($client, $spy, 'test-token');

        $first  = $source->priceFor(new \DateTimeImmutable('2026-10-29'));
        $second = $source->priceFor(new \DateTimeImmutable('2026-10-29'));

        self::assertNull($first,  'first call: no trains → null expected');
        self::assertNull($second, 'second call: cache-hit must return null too, not Money(0) from a broken spy');
        self::assertSame(1, $calls, 'only one HTTP call: the second must come from cache');
    }

    public function test_second_call_with_valid_price_returns_correct_price_and_causes_only_one_http_call(): void
    {
        $calls = 0;
        $client = new MockHttpClient(function () use (&$calls) {
            ++$calls;

            return new MockResponse(self::fixture());
        });

        $spy    = new SpyCacheItemPool();
        $source = new ApifyTrainPriceSource($client, $spy, 'test-token');

        $first  = $source->priceFor(new \DateTimeImmutable('2026-10-29'));
        $second = $source->priceFor(new \DateTimeImmutable('2026-10-29'));

        self::assertNotNull($first,  'first call must return a valid price');
        self::assertSame(2250, $first->cents,  'first call: cheapest train is €22.50');
        self::assertNotNull($second, 'second call: cache-hit must return the same price, not null from a broken spy');
        self::assertSame(2250, $second->cents, 'second call: cache-hit must return €22.50, not €0 from a broken spy');
        self::assertSame(1, $calls, 'only one HTTP call: the second must come from cache');
    }

    private static function fixture(): string
    {
        return file_get_contents(__DIR__.'/../../Fixtures/apify-vr-hel-tku.json');
    }
}
