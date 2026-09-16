<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Source;

use App\Domain\Flight\Airport;
use App\Domain\Flight\Carrier;
use App\Domain\Flight\RoutePattern;
use App\Infrastructure\Source\BrightDataFlightSource;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class BrightDataFlightSourceTest extends TestCase
{
    public function test_it_maps_a_recorded_response_to_itineraries(): void
    {
        $source = self::sourceReturning(self::fixture());

        $found = $source->search(Airport::Ams, Airport::Hel, new \DateTimeImmutable('2026-10-29'));

        self::assertCount(3, $found, 'best_flights en other_flights worden samengevoegd');

        $first = $found[0];
        self::assertSame(Carrier::Finnair, $first->carrier);
        self::assertSame(13400, $first->basePrice->cents);
        self::assertSame('2026-10-29 13:00', $first->departAt()->format('Y-m-d H:i'));
        self::assertSame('2026-10-29 16:45', $first->arriveAt()->format('Y-m-d H:i'));
        self::assertSame('AY842', $first->segments[0]->flightNumber);
    }

    public function test_a_journey_ending_in_helsinki_gets_the_train_pattern(): void
    {
        $source = self::sourceReturning(self::fixture());

        $found = $source->search(Airport::Ams, Airport::Hel, new \DateTimeImmutable('2026-10-29'));

        self::assertSame(RoutePattern::ViaHelsinkiWithTrain, $found[0]->pattern);
    }

    public function test_unknown_airlines_are_skipped_rather_than_crashing(): void
    {
        $source = self::sourceReturning(json_encode([
            'best_flights' => [[
                'flights' => [[
                    'departure_airport' => ['id' => 'AMS', 'time' => '2026-10-29 06:00'],
                    'arrival_airport' => ['id' => 'HEL', 'time' => '2026-10-29 09:45'],
                    'airline' => 'Ryanair',
                    'flight_number' => 'FR 123',
                ]],
                'price' => 49,
            ]],
        ], JSON_THROW_ON_ERROR));

        self::assertSame([], $source->search(Airport::Ams, Airport::Hel, new \DateTimeImmutable('2026-10-29')));
    }

    public function test_an_empty_response_yields_no_itineraries(): void
    {
        $source = self::sourceReturning('{}');

        self::assertSame([], $source->search(Airport::Ams, Airport::Hel, new \DateTimeImmutable('2026-10-29')));
    }

    public function test_the_request_carries_the_api_token(): void
    {
        $seen = null;
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$seen) {
            $seen = $options;

            return new MockResponse(self::fixture());
        });

        (new BrightDataFlightSource($client, 'test-token', 'serp_zone'))
            ->search(Airport::Ams, Airport::Hel, new \DateTimeImmutable('2026-10-29'));

        self::assertContains('Authorization: Bearer test-token', $seen['headers']);
    }

    private static function sourceReturning(string $body): BrightDataFlightSource
    {
        return new BrightDataFlightSource(
            new MockHttpClient(new MockResponse($body)),
            'test-token',
            'serp_zone',
        );
    }

    private static function fixture(): string
    {
        return file_get_contents(__DIR__.'/../../Fixtures/brightdata-ams-hel.json');
    }
}
