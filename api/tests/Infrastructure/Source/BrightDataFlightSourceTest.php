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

    public function test_a_journey_starting_in_helsinki_also_gets_the_train_pattern(): void
    {
        $source = self::sourceReturning(json_encode([
            'best_flights' => [[
                'flights' => [[
                    'departure_airport' => ['id' => 'HEL', 'time' => '2026-10-25 14:00'],
                    'arrival_airport'   => ['id' => 'AMS', 'time' => '2026-10-25 16:45'],
                    'airline'           => 'Finnair',
                    'flight_number'     => 'AY 841',
                ]],
                'price' => 80,
            ]],
        ], JSON_THROW_ON_ERROR));

        $found = $source->search(Airport::Hel, Airport::Ams, new \DateTimeImmutable('2026-10-25'));

        self::assertCount(1, $found);
        self::assertSame(
            RoutePattern::ViaHelsinkiWithTrain,
            $found[0]->pattern,
            'Een vlucht vanuit Helsinki hoort patroon ViaHelsinkiWithTrain te krijgen.',
        );
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

    public function test_an_offer_with_an_unparseable_time_is_skipped(): void
    {
        $source = self::sourceReturning(json_encode([
            'best_flights' => [
                // Geldig aanbod: Finnair AMS→HEL
                [
                    'flights' => [[
                        'departure_airport' => ['id' => 'AMS', 'time' => '2026-10-29 13:00'],
                        'arrival_airport'   => ['id' => 'HEL', 'time' => '2026-10-29 16:45'],
                        'airline'           => 'Finnair',
                        'flight_number'     => 'AY 842',
                    ]],
                    'price' => 134,
                ],
                // Rot aanbod: onleesbaar tijdstip — mag de geldige aanbieding niet meesleuren
                [
                    'flights' => [[
                        'departure_airport' => ['id' => 'AMS', 'time' => 'N/A'],
                        'arrival_airport'   => ['id' => 'HEL', 'time' => '2026-10-29 16:45'],
                        'airline'           => 'Finnair',
                        'flight_number'     => 'AY 843',
                    ]],
                    'price' => 99,
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        $found = $source->search(Airport::Ams, Airport::Hel, new \DateTimeImmutable('2026-10-29'));

        self::assertCount(1, $found, 'Het rotte aanbod wordt overgeslagen; het geldige blijft staan');
        self::assertSame('AY842', $found[0]->segments[0]->flightNumber);
    }

    public function test_an_offer_with_a_missing_time_key_is_skipped(): void
    {
        $source = self::sourceReturning(json_encode([
            'best_flights' => [
                // Geldig aanbod: Finnair AMS→HEL
                [
                    'flights' => [[
                        'departure_airport' => ['id' => 'AMS', 'time' => '2026-10-29 13:00'],
                        'arrival_airport'   => ['id' => 'HEL', 'time' => '2026-10-29 16:45'],
                        'airline'           => 'Finnair',
                        'flight_number'     => 'AY 842',
                    ]],
                    'price' => 134,
                ],
                // Rot aanbod: ontbrekende 'time'-sleutel — mag de geldige aanbieding niet meesleuren
                // en mag ook niet stilzwijgend het huidige tijdstip invullen
                [
                    'flights' => [[
                        'departure_airport' => ['id' => 'AMS'],
                        'arrival_airport'   => ['id' => 'HEL', 'time' => '2026-10-29 16:45'],
                        'airline'           => 'Finnair',
                        'flight_number'     => 'AY 843',
                    ]],
                    'price' => 99,
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        $found = $source->search(Airport::Ams, Airport::Hel, new \DateTimeImmutable('2026-10-29'));

        self::assertCount(1, $found, 'Het aanbod zonder tijdsleutel wordt overgeslagen; het geldige blijft staan');
        self::assertSame('AY842', $found[0]->segments[0]->flightNumber);
    }

    public function test_an_offer_whose_arrival_precedes_departure_is_skipped(): void
    {
        $source = self::sourceReturning(json_encode([
            'best_flights' => [[
                'flights' => [[
                    'departure_airport' => ['id' => 'AMS', 'time' => '2026-10-29 16:00'],
                    'arrival_airport'   => ['id' => 'HEL', 'time' => '2026-10-29 13:00'],
                    'airline'           => 'Finnair',
                    'flight_number'     => 'AY 999',
                ]],
                'price' => 134,
            ]],
        ], JSON_THROW_ON_ERROR));

        $found = $source->search(Airport::Ams, Airport::Hel, new \DateTimeImmutable('2026-10-29'));

        self::assertSame([], $found, 'Aanbieding met aankomst vóór vertrek wordt overgeslagen');
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
