<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Source;

use App\Domain\Flight\Airport;
use App\Domain\Flight\Carrier;
use App\Domain\Flight\RoutePattern;
use App\Infrastructure\Source\BrightDataFlightSource;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
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

    public function test_an_offer_with_a_non_numeric_price_is_skipped(): void
    {
        $source = self::sourceReturning(json_encode([
            'best_flights' => [
                // Geldig aanbod
                [
                    'flights' => [[
                        'departure_airport' => ['id' => 'AMS', 'time' => '2026-10-29 13:00'],
                        'arrival_airport'   => ['id' => 'HEL', 'time' => '2026-10-29 16:45'],
                        'airline'           => 'Finnair',
                        'flight_number'     => 'AY 842',
                    ]],
                    'price' => 134,
                ],
                // Rot aanbod: niet-numerieke prijs
                [
                    'flights' => [[
                        'departure_airport' => ['id' => 'AMS', 'time' => '2026-10-29 13:00'],
                        'arrival_airport'   => ['id' => 'HEL', 'time' => '2026-10-29 16:45'],
                        'airline'           => 'Finnair',
                        'flight_number'     => 'AY 843',
                    ]],
                    'price' => 'N/A',
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        $found = $source->search(Airport::Ams, Airport::Hel, new \DateTimeImmutable('2026-10-29'));

        self::assertCount(1, $found, 'Aanbod met "N/A" als prijs wordt overgeslagen');
        self::assertSame('AY842', $found[0]->segments[0]->flightNumber);
    }

    public function test_an_offer_with_a_locale_formatted_price_is_skipped(): void
    {
        // '1.234,00' (Nederlandse notatie) wordt door (float) '1.234,00' → 1.234 → €1,23.
        // Dat is een vlucht van €1234 die als €1,23 bovenaan komt. Overslaan.
        $source = self::sourceReturning(json_encode([
            'best_flights' => [
                [
                    'flights' => [[
                        'departure_airport' => ['id' => 'AMS', 'time' => '2026-10-29 13:00'],
                        'arrival_airport'   => ['id' => 'HEL', 'time' => '2026-10-29 16:45'],
                        'airline'           => 'Finnair',
                        'flight_number'     => 'AY 842',
                    ]],
                    'price' => '1.234,00',
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        $found = $source->search(Airport::Ams, Airport::Hel, new \DateTimeImmutable('2026-10-29'));

        self::assertSame([], $found, 'Aanbod met locale-prijs "1.234,00" wordt overgeslagen');
    }

    public function test_an_offer_with_a_negative_price_is_skipped_without_crashing_the_search(): void
    {
        $source = self::sourceReturning(json_encode([
            'best_flights' => [
                // Geldig aanbod
                [
                    'flights' => [[
                        'departure_airport' => ['id' => 'AMS', 'time' => '2026-10-29 13:00'],
                        'arrival_airport'   => ['id' => 'HEL', 'time' => '2026-10-29 16:45'],
                        'airline'           => 'Finnair',
                        'flight_number'     => 'AY 842',
                    ]],
                    'price' => 134,
                ],
                // Rot aanbod: negatieve prijs van -0.004
                // Dit geval dekt de guard zelf: zonder de < 0-check wordt
                // (int) round(-0.4) = 0 cent — een gratis spookvlucht.
                [
                    'flights' => [[
                        'departure_airport' => ['id' => 'AMS', 'time' => '2026-10-29 13:00'],
                        'arrival_airport'   => ['id' => 'HEL', 'time' => '2026-10-29 16:45'],
                        'airline'           => 'Finnair',
                        'flight_number'     => 'AY 843',
                    ]],
                    'price' => -0.004,
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        $found = $source->search(Airport::Ams, Airport::Hel, new \DateTimeImmutable('2026-10-29'));

        self::assertCount(1, $found, 'Negatieve prijs (-0.004) wordt overgeslagen; zonder de < 0-guard zou het 0 cent worden');
        self::assertSame('AY842', $found[0]->segments[0]->flightNumber);
    }

    public function test_an_offer_with_a_comma_decimal_price_is_skipped(): void
    {
        // '134,50' (komma als decimaalscheider) → is_numeric() geeft false terug.
        // Vóór de guard werd dit door (float) '134,50' → 134.0 → €134,00 verwerkt,
        // waardoor de centen onzichtbaar werden afgekapt.
        $source = self::sourceReturning(json_encode([
            'best_flights' => [
                // Geldig aanbod
                [
                    'flights' => [[
                        'departure_airport' => ['id' => 'AMS', 'time' => '2026-10-29 13:00'],
                        'arrival_airport'   => ['id' => 'HEL', 'time' => '2026-10-29 16:45'],
                        'airline'           => 'Finnair',
                        'flight_number'     => 'AY 842',
                    ]],
                    'price' => 134,
                ],
                // Rot aanbod: komma als decimaalscheider
                [
                    'flights' => [[
                        'departure_airport' => ['id' => 'AMS', 'time' => '2026-10-29 13:00'],
                        'arrival_airport'   => ['id' => 'HEL', 'time' => '2026-10-29 16:45'],
                        'airline'           => 'Finnair',
                        'flight_number'     => 'AY 843',
                    ]],
                    'price' => '134,50',
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        $found = $source->search(Airport::Ams, Airport::Hel, new \DateTimeImmutable('2026-10-29'));

        self::assertCount(1, $found, 'Aanbod met "134,50" als prijs wordt overgeslagen (centen mogen niet verdwijnen)');
        self::assertSame('AY842', $found[0]->segments[0]->flightNumber);
    }

    public function test_an_offer_with_an_overflow_price_is_skipped_without_crashing_the_search(): void
    {
        // Een extreem grote float (9e18) valt boven de bovengrens van 1_000_000.0 en wordt
        // overgeslagen door de is_finite + bovengrens-check, vóór Money::fromEuros() bereikt wordt.
        $source = self::sourceReturning(json_encode([
            'best_flights' => [
                // Geldig aanbod
                [
                    'flights' => [[
                        'departure_airport' => ['id' => 'AMS', 'time' => '2026-10-29 13:00'],
                        'arrival_airport'   => ['id' => 'HEL', 'time' => '2026-10-29 16:45'],
                        'airline'           => 'Finnair',
                        'flight_number'     => 'AY 842',
                    ]],
                    'price' => 134,
                ],
                // Rot aanbod: overflow-prijs boven de bovengrens
                [
                    'flights' => [[
                        'departure_airport' => ['id' => 'AMS', 'time' => '2026-10-29 13:00'],
                        'arrival_airport'   => ['id' => 'HEL', 'time' => '2026-10-29 16:45'],
                        'airline'           => 'Finnair',
                        'flight_number'     => 'AY 843',
                    ]],
                    'price' => 9e18,
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        $found = $source->search(Airport::Ams, Airport::Hel, new \DateTimeImmutable('2026-10-29'));

        self::assertCount(1, $found, 'Overflow-prijs (9e18) wordt overgeslagen zonder de hele zoekopdracht te laten crashen');
        self::assertSame('AY842', $found[0]->segments[0]->flightNumber);
    }

    public function test_an_offer_with_an_inf_price_is_skipped_without_becoming_a_free_flight(): void
    {
        // json_decode maakt van "price": 1e999 gewoon INF (een geldige PHP float).
        // is_numeric(INF) = true en INF < 0 = false, waardoor de oude guard werd gepasseerd.
        // (int) round(INF * 100) = 0 cent — een spookvlucht van €0,00 die bovenaan de lijst komt.
        // De is_finite()-check sluit dit uit.
        // Opmerking: json_encode gooit een JsonException op INF, dus het JSON-antwoord wordt
        // hier als raw string aangeboden — exact zoals json_decode het uit 1e999 zou produceren.
        $json = '{"best_flights":[' .
            '{"flights":[{"departure_airport":{"id":"AMS","time":"2026-10-29 13:00"},' .
            '"arrival_airport":{"id":"HEL","time":"2026-10-29 16:45"},' .
            '"airline":"Finnair","flight_number":"AY 842"}],"price":134},' .
            '{"flights":[{"departure_airport":{"id":"AMS","time":"2026-10-29 13:00"},' .
            '"arrival_airport":{"id":"HEL","time":"2026-10-29 16:45"},' .
            '"airline":"Finnair","flight_number":"AY 843"}],"price":1e999}]}';

        $found = self::sourceReturning($json)->search(Airport::Ams, Airport::Hel, new \DateTimeImmutable('2026-10-29'));

        self::assertCount(1, $found, 'INF-prijs (1e999) wordt overgeslagen; zonder is_finite()-check zou het een gratis spookvlucht worden');
        self::assertSame('AY842', $found[0]->segments[0]->flightNumber);
    }

    public function test_an_offer_with_a_price_above_one_million_is_skipped_without_crashing_the_search(): void
    {
        // 92233720368547744 passeert is_numeric() en is niet negatief, maar
        // (int) round(92233720368547744 * 100) overflowt stil naar een positieve int.
        // Daarna loopt Money::add() over van int naar float, waarna Money::__construct(int)
        // een TypeError gooit — geen InvalidArgumentException, dus de catch pakt hem niet.
        // Resultaat: een 500 op de hele zoekopdracht. De bovengrens van 1_000_000.0 sluit dit uit.
        $source = self::sourceReturning(json_encode([
            'best_flights' => [
                // Geldig aanbod
                [
                    'flights' => [[
                        'departure_airport' => ['id' => 'AMS', 'time' => '2026-10-29 13:00'],
                        'arrival_airport'   => ['id' => 'HEL', 'time' => '2026-10-29 16:45'],
                        'airline'           => 'Finnair',
                        'flight_number'     => 'AY 842',
                    ]],
                    'price' => 134,
                ],
                // Rot aanbod: prijs die int-bereik sloopt via Money::add()
                [
                    'flights' => [[
                        'departure_airport' => ['id' => 'AMS', 'time' => '2026-10-29 13:00'],
                        'arrival_airport'   => ['id' => 'HEL', 'time' => '2026-10-29 16:45'],
                        'airline'           => 'Finnair',
                        'flight_number'     => 'AY 843',
                    ]],
                    'price' => 92233720368547744,
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        $found = $source->search(Airport::Ams, Airport::Hel, new \DateTimeImmutable('2026-10-29'));

        self::assertCount(1, $found, 'Prijs boven 1_000_000 wordt overgeslagen; zonder bovengrens zou Money::add() de hele zoekopdracht laten crashen');
        self::assertSame('AY842', $found[0]->segments[0]->flightNumber);
    }

    public function test_skipped_offers_are_logged_as_a_warning(): void
    {
        $warnings = [];

        $logger = new class($warnings) extends AbstractLogger {
            public function __construct(private array &$log) {}

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->log[] = ['level' => $level, 'message' => $message, 'context' => $context];
            }
        };

        $source = new BrightDataFlightSource(
            new MockHttpClient(new MockResponse(json_encode([
                'best_flights' => [
                    // Geldig aanbod
                    [
                        'flights' => [[
                            'departure_airport' => ['id' => 'AMS', 'time' => '2026-10-29 13:00'],
                            'arrival_airport'   => ['id' => 'HEL', 'time' => '2026-10-29 16:45'],
                            'airline'           => 'Finnair',
                            'flight_number'     => 'AY 842',
                        ]],
                        'price' => 134,
                    ],
                    // Rot aanbod: niet-numerieke prijs
                    [
                        'flights' => [[
                            'departure_airport' => ['id' => 'AMS', 'time' => '2026-10-29 13:00'],
                            'arrival_airport'   => ['id' => 'HEL', 'time' => '2026-10-29 16:45'],
                            'airline'           => 'Finnair',
                            'flight_number'     => 'AY 843',
                        ]],
                        'price' => 'N/A',
                    ],
                ],
            ], JSON_THROW_ON_ERROR))),
            'test-token',
            'serp_zone',
            $logger,
        );

        $source->search(Airport::Ams, Airport::Hel, new \DateTimeImmutable('2026-10-29'));

        self::assertCount(1, $warnings, 'Er moet precies één logmelding zijn voor de overgeslagen aanbiedingen');
        self::assertSame('warning', $warnings[0]['level']);
        // PSR-3: de boodschap bevat placeholders; de waarden zitten in de context-array.
        // Scherpe asserties op zowel het template als de context, zodat een verkeerde
        // teller (bijv. 2 i.p.v. 1) of een verkeerde route de test laat zakken.
        self::assertStringContainsString('{count} aanbod(en) overgeslagen', $warnings[0]['message'],
            'De logmelding gebruikt PSR-3 placeholder {count}');
        self::assertSame(1, $warnings[0]['context']['count'],
            'Context-array bevat het exacte aantal overgeslagen aanbiedingen');
        self::assertSame('AMS', $warnings[0]['context']['from'],
            'Context-array bevat de vertrekhaven');
        self::assertSame('HEL', $warnings[0]['context']['to'],
            'Context-array bevat de bestemmingshaven');
    }

    public function test_a_payload_without_flight_collections_is_logged_as_a_warning(): void
    {
        // Als best_flights én other_flights beide ontbreken (hernoemde sleutel, foutobject,
        // lege payload), was het gedrag stilletjes 0 resultaten — exact het scenario uit de bevinding.
        $warnings = [];

        $logger = new class($warnings) extends AbstractLogger {
            public function __construct(private array &$log) {}

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->log[] = ['level' => $level, 'message' => $message, 'context' => $context];
            }
        };

        $source = new BrightDataFlightSource(
            new MockHttpClient(new MockResponse(json_encode([
                'top_flights' => [], // hernoemde collectie — geen van beide bekende sleutels aanwezig
            ], JSON_THROW_ON_ERROR))),
            'test-token',
            'serp_zone',
            $logger,
        );

        $result = $source->search(Airport::Ams, Airport::Hel, new \DateTimeImmutable('2026-10-29'));

        self::assertSame([], $result, 'Geen resultaten verwacht');
        self::assertCount(1, $warnings, 'Ontbrekende collectie moet één waarschuwing geven');
        self::assertSame('warning', $warnings[0]['level']);
        self::assertStringContainsString('Geen vluchtcollectie', $warnings[0]['message'],
            'De logmelding signaleert het ontbreken van vluchtcollecties');
        self::assertSame('AMS', $warnings[0]['context']['from']);
        self::assertSame('HEL', $warnings[0]['context']['to']);
    }

    public function test_an_error_object_payload_is_logged_as_a_warning(): void
    {
        // {"error":"quota exceeded"} bevat ook geen best_flights of other_flights.
        // Vroeger: 0 resultaten, 0 logregels — volledig stil.
        $warnings = [];

        $logger = new class($warnings) extends AbstractLogger {
            public function __construct(private array &$log) {}

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->log[] = ['level' => $level, 'message' => $message, 'context' => $context];
            }
        };

        $source = new BrightDataFlightSource(
            new MockHttpClient(new MockResponse(json_encode([
                'error' => 'quota exceeded',
            ], JSON_THROW_ON_ERROR))),
            'test-token',
            'serp_zone',
            $logger,
        );

        $result = $source->search(Airport::Ams, Airport::Hel, new \DateTimeImmutable('2026-10-29'));

        self::assertSame([], $result, 'Geen resultaten verwacht bij foutobject');
        self::assertCount(1, $warnings, 'Foutobject-payload moet één waarschuwing geven');
        self::assertSame('warning', $warnings[0]['level']);
        self::assertStringContainsString('Geen vluchtcollectie', $warnings[0]['message'],
            'De logmelding signaleert het ontbreken van vluchtcollecties ook bij een foutobject');
    }

    public function test_an_offer_with_price_zero_is_skipped_without_becoming_a_free_flight(): void
    {
        // Prijs 0 passeert de oude guard (< 0.0) en wordt 0 cent — een gratis spookvlucht.
        // De nieuwe guard (<= 0.0) moet dit aanbod overslaan.
        $source = self::sourceReturning(json_encode([
            'best_flights' => [
                // Geldig aanbod
                [
                    'flights' => [[
                        'departure_airport' => ['id' => 'AMS', 'time' => '2026-10-29 13:00'],
                        'arrival_airport'   => ['id' => 'HEL', 'time' => '2026-10-29 16:45'],
                        'airline'           => 'Finnair',
                        'flight_number'     => 'AY 842',
                    ]],
                    'price' => 80,
                ],
                // Rot aanbod: prijs 0
                [
                    'flights' => [[
                        'departure_airport' => ['id' => 'AMS', 'time' => '2026-10-29 13:00'],
                        'arrival_airport'   => ['id' => 'HEL', 'time' => '2026-10-29 16:45'],
                        'airline'           => 'Finnair',
                        'flight_number'     => 'AY 999',
                    ]],
                    'price' => 0,
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        $found = $source->search(Airport::Ams, Airport::Hel, new \DateTimeImmutable('2026-10-29'));

        self::assertCount(1, $found, 'Prijs 0 wordt overgeslagen; zonder <= 0-guard wordt het een gratis spookvlucht van 0 cent');
        self::assertSame('AY842', $found[0]->segments[0]->flightNumber);
    }

    public function test_an_offer_with_price_that_rounds_to_zero_cents_is_skipped(): void
    {
        // 0.004 euro rond af naar 0 cent. De oude guard (< 0.0) liet dit door.
        // De nieuwe guard (<= 0.0) slaat dit aanbod ook over.
        $source = self::sourceReturning(json_encode([
            'best_flights' => [
                // Geldig aanbod
                [
                    'flights' => [[
                        'departure_airport' => ['id' => 'AMS', 'time' => '2026-10-29 13:00'],
                        'arrival_airport'   => ['id' => 'HEL', 'time' => '2026-10-29 16:45'],
                        'airline'           => 'Finnair',
                        'flight_number'     => 'AY 842',
                    ]],
                    'price' => 80,
                ],
                // Rot aanbod: prijs 0.004 (rondt af naar 0 cent)
                [
                    'flights' => [[
                        'departure_airport' => ['id' => 'AMS', 'time' => '2026-10-29 13:00'],
                        'arrival_airport'   => ['id' => 'HEL', 'time' => '2026-10-29 16:45'],
                        'airline'           => 'Finnair',
                        'flight_number'     => 'AY 999',
                    ]],
                    'price' => 0.004,
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        $found = $source->search(Airport::Ams, Airport::Hel, new \DateTimeImmutable('2026-10-29'));

        self::assertCount(1, $found, 'Prijs 0.004 rondt af naar 0 cent en wordt overgeslagen; het geldige aanbod blijft');
        self::assertSame('AY842', $found[0]->segments[0]->flightNumber);
    }

    private static function sourceReturning(string $body, ?\Psr\Log\LoggerInterface $logger = null): BrightDataFlightSource
    {
        return new BrightDataFlightSource(
            new MockHttpClient(new MockResponse($body)),
            'test-token',
            'serp_zone',
            $logger,
        );
    }

    private static function fixture(): string
    {
        return file_get_contents(__DIR__.'/../../Fixtures/brightdata-ams-hel.json');
    }
}
