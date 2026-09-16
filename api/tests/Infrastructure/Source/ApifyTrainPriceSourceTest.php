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

        self::assertSame(0, $price->cents, 'een prijs van €0 is een geldige (gratis) rit en mag niet worden weggefilterd');
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
        self::assertSame(1, $calls, 'null-uitkomst moet worden gecacht (15 min) zodat niet elke klik een Apify-run kost');
    }

    public function test_a_malformed_response_is_not_cached(): void
    {
        $calls = 0;
        $client = new MockHttpClient(function () use (&$calls) {
            ++$calls;

            if (1 === $calls) {
                return new MockResponse('dit is geen json!');
            }

            return new MockResponse(self::fixture());
        });

        $source = new ApifyTrainPriceSource($client, new ArrayAdapter(), 'test-token');

        try {
            $source->priceFor(new \DateTimeImmutable('2026-10-29'));
            self::fail('verwachtte een JsonException bij ongeldig JSON');
        } catch (\JsonException) {
            // verwacht
        }

        $price = $source->priceFor(new \DateTimeImmutable('2026-10-29'));
        self::assertSame(2250, $price->cents, 'na een mislukte aanroep moet een volgende aanroep de juiste prijs geven');
        self::assertSame(2, $calls, 'mislukte aanroep mag niet in de cache blijven hangen');
    }

    public function test_a_non_numeric_price_in_the_list_is_filtered_out(): void
    {
        // "N/A" wordt via (float) stilzwijgend 0.0 — een gratis treinrit die er niet is.
        // De guard filtert niet-numerieke waarden vóór de cast weg.
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

        self::assertSame(2250, $price->cents, '"N/A" wordt overgeslagen; de geldige prijs van €22,50 blijft over');
    }

    public function test_a_negative_price_in_the_list_is_filtered_out(): void
    {
        // Een negatieve treinprijs geeft zonder guard HTTP 500 (Money::__construct gooit).
        // De guard filtert negatieve waarden weg.
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

        self::assertSame(2250, $price->cents, 'Negatieve prijs (-5.0) wordt overgeslagen; de geldige prijs van €22,50 blijft over');
    }

    public function test_an_absurdly_high_price_does_not_crash_the_search(): void
    {
        // 1e17 euro is eindig en niet-negatief, dus het passeerde alle oude filters.
        // (int) round(1e17 * 100) loopt over naar -8446744073709551616, waarop
        // Money::fromCents() een InvalidArgumentException gooit: HTTP 500 op de
        // hele zoekopdracht. De bovengrens sluit dit uit.
        $json = json_encode([
            ['priceEur' => 1e17, 'departureTime' => '2026-10-29T08:00:00', 'trainType' => 'IC'],
        ]);

        $source = new ApifyTrainPriceSource(
            new MockHttpClient(new MockResponse($json)),
            new ArrayAdapter(),
            'test-token',
        );

        $price = $source->priceFor(new \DateTimeImmutable('2026-10-29'));

        self::assertNull($price, 'Een absurd hoge prijs wordt weggefilterd; er blijft geen bruikbare prijs over');
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

    private static function fixture(): string
    {
        return file_get_contents(__DIR__.'/../../Fixtures/apify-vr-hel-tku.json');
    }
}
