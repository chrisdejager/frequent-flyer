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
        // Een extreem grote float (9e18) valt boven de bovengrens (MAX_TICKET_PRICE_EUROS) en wordt
        // overgeslagen, vóór Money::fromEuros() bereikt wordt.
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

    public function test_an_offer_with_an_inf_price_does_not_become_a_free_flight(): void
    {
        // json_decode maakt van "price": 1e999 gewoon INF (een geldige PHP float).
        // is_numeric(INF) = true en INF < 0 = false, waardoor de prijs de eerste guards passeert.
        // INF wordt gevangen door de bovengrens (INF > 1_000_000.0 is waar); daarnaast zou
        // (int) round(INF * 100) = 0 zijn, zodat ook de centen-guard het zou tegenhouden.
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

        self::assertCount(1, $found, 'INF-prijs (1e999) wordt gevangen door de bovengrens (INF > 1_000_000.0 is waar)');
        self::assertSame('AY842', $found[0]->segments[0]->flightNumber);
    }

    public function test_an_offer_with_a_price_above_one_million_is_skipped_without_crashing_the_search(): void
    {
        // 92233720368547744 passeert is_numeric() en is niet negatief, maar
        // (int) round(92233720368547744 * 100) overflowt stil naar een positieve int.
        // Daarna loopt Money::add() over van int naar float, waarna Money::__construct(int)
        // een TypeError gooit — geen InvalidArgumentException, dus de catch pakt hem niet.
        // Resultaat: een 500 op de hele zoekopdracht. De bovengrens (MAX_TICKET_PRICE_EUROS) sluit dit uit.
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

        self::assertCount(1, $found, 'Prijs boven MAX_TICKET_PRICE_EUROS wordt overgeslagen; zonder bovengrens zou Money::add() de hele zoekopdracht laten crashen');
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
                    // Rot aanbod 1: niet-numerieke prijs
                    [
                        'flights' => [[
                            'departure_airport' => ['id' => 'AMS', 'time' => '2026-10-29 13:00'],
                            'arrival_airport'   => ['id' => 'HEL', 'time' => '2026-10-29 16:45'],
                            'airline'           => 'Finnair',
                            'flight_number'     => 'AY 843',
                        ]],
                        'price' => 'N/A',
                    ],
                    // Rot aanbod 2: negatieve prijs — cents <= 0-guard slaat dit over
                    [
                        'flights' => [[
                            'departure_airport' => ['id' => 'AMS', 'time' => '2026-10-29 13:00'],
                            'arrival_airport'   => ['id' => 'HEL', 'time' => '2026-10-29 16:45'],
                            'airline'           => 'Finnair',
                            'flight_number'     => 'AY 844',
                        ]],
                        'price' => -5,
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
        // teller (bijv. 1 i.p.v. 2) of een verkeerde route de test laat zakken.
        self::assertStringContainsString('{count} aanbod(en) overgeslagen', $warnings[0]['message'],
            'De logmelding gebruikt PSR-3 placeholder {count}');
        self::assertSame(2, $warnings[0]['context']['count'],
            'Context-array bevat het exacte aantal overgeslagen aanbiedingen (2 rotte in deze payload)');
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

    public function test_a_scalar_best_flights_value_yields_a_warning_and_empty_result(): void
    {
        // best_flights: "oops" gaf eerder HTTP 500 ("Only arrays and Traversables can be unpacked").
        // Na de fix: een waarschuwing + leeg resultaat.
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
                'best_flights' => 'oops',
            ], JSON_THROW_ON_ERROR))),
            'test-token',
            'serp_zone',
            $logger,
        );

        $result = $source->search(Airport::Ams, Airport::Hel, new \DateTimeImmutable('2026-10-29'));

        self::assertSame([], $result, 'Scalaire best_flights geeft leeg resultaat, geen 500');
        self::assertCount(1, $warnings, 'Er moet één waarschuwing zijn');
        self::assertSame('warning', $warnings[0]['level']);
        self::assertStringContainsString('Geen vluchtcollectie', $warnings[0]['message']);
    }

    public function test_non_array_elements_in_best_flights_are_skipped(): void
    {
        // best_flights: [1, 2] gaf eerder TypeError in toItinerary().
        // Na de fix: de losse ints worden overgeslagen, geen crash.
        $source = self::sourceReturning(json_encode([
            'best_flights' => [
                1,  // geen array → overslaan
                2,  // geen array → overslaan
                [   // geldig aanbod
                    'flights' => [[
                        'departure_airport' => ['id' => 'AMS', 'time' => '2026-10-29 13:00'],
                        'arrival_airport'   => ['id' => 'HEL', 'time' => '2026-10-29 16:45'],
                        'airline'           => 'Finnair',
                        'flight_number'     => 'AY 842',
                    ]],
                    'price' => 134,
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        $found = $source->search(Airport::Ams, Airport::Hel, new \DateTimeImmutable('2026-10-29'));

        self::assertCount(1, $found, 'Scalaire elementen worden overgeslagen; het geldige aanbod blijft');
        self::assertSame('AY842', $found[0]->segments[0]->flightNumber);
    }

    public function test_http_error_returns_empty_array_and_logs_warning(): void
    {
        // Bevinding 3: een HTTP-fout (bijv. 429 quota exceeded) mag niet leiden tot HTTP 500.
        // De treinbron vat dit al op; de vluchtbron moet hetzelfde doen.
        $warnings = [];
        $logger = new class($warnings) extends \Psr\Log\AbstractLogger {
            public function __construct(private array &$log) {}
            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->log[] = ['level' => $level, 'message' => (string) $message];
            }
        };

        $client = new \Symfony\Component\HttpClient\MockHttpClient(
            new \Symfony\Component\HttpClient\Response\MockResponse('', ['http_code' => 429]),
        );

        $source = new BrightDataFlightSource($client, 'test-token', 'serp_zone', $logger);
        $result = $source->search(Airport::Ams, Airport::Hel, new \DateTimeImmutable('2026-10-29'));

        self::assertSame([], $result, 'HTTP-fout moet lege array teruggeven, geen exception');
        self::assertCount(1, $warnings, 'HTTP-fout moet als warning worden gelogd');
        self::assertSame('warning', $warnings[0]['level']);
    }

    public function test_non_json_response_body_returns_empty_array_and_logs_warning(): void
    {
        // Bevinding 3: een HTML-body (bijv. foutpagina van de proxy) gooit JsonException.
        // Dit mag niet leiden tot HTTP 500; spiegelt ApifyTrainPriceSource::fetch().
        $warnings = [];
        $logger = new class($warnings) extends \Psr\Log\AbstractLogger {
            public function __construct(private array &$log) {}
            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->log[] = ['level' => $level, 'message' => (string) $message];
            }
        };

        $client = new \Symfony\Component\HttpClient\MockHttpClient(
            new \Symfony\Component\HttpClient\Response\MockResponse('<html>Error</html>'),
        );

        $source = new BrightDataFlightSource($client, 'test-token', 'serp_zone', $logger);
        $result = $source->search(Airport::Ams, Airport::Hel, new \DateTimeImmutable('2026-10-29'));

        self::assertSame([], $result, 'Niet-JSON body moet lege array teruggeven, geen exception');
        self::assertCount(1, $warnings, 'JSON-fout moet als warning worden gelogd');
        self::assertSame('warning', $warnings[0]['level']);
    }

    public function test_two_leg_offer_with_both_allowed_carriers_is_accepted(): void
    {
        // Patroon B: SAS AMS→ARN + KLM ARN→TKU (beide toegestaan)
        $source = self::sourceReturning(json_encode([
            'best_flights' => [[
                'flights' => [
                    [
                        'departure_airport' => ['id' => 'AMS', 'time' => '2026-10-29 09:00'],
                        'arrival_airport'   => ['id' => 'ARN', 'time' => '2026-10-29 11:00'],
                        'airline'           => 'SAS',
                        'flight_number'     => 'SK552',
                    ],
                    [
                        'departure_airport' => ['id' => 'ARN', 'time' => '2026-10-29 12:30'],
                        'arrival_airport'   => ['id' => 'TKU', 'time' => '2026-10-29 14:10'],
                        'airline'           => 'KLM',
                        'flight_number'     => 'KL789',
                    ],
                ],
                'price' => 150,
            ]],
        ], JSON_THROW_ON_ERROR));

        $found = $source->search(Airport::Ams, Airport::Tku, new \DateTimeImmutable('2026-10-29'));

        self::assertCount(1, $found, 'Aanbod met twee toegestane maatschappijen (SAS+KLM) moet worden geaccepteerd');
        // KLM heeft hogere prioriteit dan SAS → carrier moet KLM zijn
        self::assertSame(Carrier::Klm, $found[0]->carrier,
            'Bij gemengde SAS+KLM legs moet KLM worden gekozen (conservatieve keuze: hoogste bagagetoeslag)');
    }

    public function test_two_leg_offer_carrier_priority_klm_first_leg_over_sas_second_leg(): void
    {
        // Spiegelstuk van test_two_leg_offer_with_both_allowed_carriers_is_accepted:
        // nu staat KLM op been 0 en SAS op been 1 (omgekeerde volgorde).
        // Garandeert dat prioriteit werkt ongeacht been-volgorde, en dat een
        // mutatie "neem carrier van het laatste been" hier rood gaat.
        $source = self::sourceReturning(json_encode([
            'best_flights' => [[
                'flights' => [
                    [
                        'departure_airport' => ['id' => 'AMS', 'time' => '2026-10-29 09:00'],
                        'arrival_airport'   => ['id' => 'ARN', 'time' => '2026-10-29 11:00'],
                        'airline'           => 'KLM',
                        'flight_number'     => 'KL789',
                    ],
                    [
                        'departure_airport' => ['id' => 'ARN', 'time' => '2026-10-29 12:30'],
                        'arrival_airport'   => ['id' => 'TKU', 'time' => '2026-10-29 14:10'],
                        'airline'           => 'SAS',
                        'flight_number'     => 'SK552',
                    ],
                ],
                'price' => 150,
            ]],
        ], JSON_THROW_ON_ERROR));

        $found = $source->search(Airport::Ams, Airport::Tku, new \DateTimeImmutable('2026-10-29'));

        self::assertCount(1, $found, 'Aanbod met KLM op been 0 en SAS op been 1 moet worden geaccepteerd');
        // KLM heeft hogere prioriteit dan SAS — en staat hier op been 0, niet been 1.
        // Een mutatie die "de carrier van het laatste been neemt" zou SAS teruggeven → rood.
        self::assertSame(Carrier::Klm, $found[0]->carrier,
            'Bij KLM op been 0 en SAS op been 1 moet KLM worden gekozen (prioriteit, niet been-volgorde)');
    }

    public function test_two_leg_offer_carrier_priority_klm_over_finnair(): void
    {
        // Patroon B: Finnair AMS→HEL + KLM HEL→TKU — KLM heeft prioriteit
        $source = self::sourceReturning(json_encode([
            'best_flights' => [[
                'flights' => [
                    [
                        'departure_airport' => ['id' => 'AMS', 'time' => '2026-10-29 09:00'],
                        'arrival_airport'   => ['id' => 'HEL', 'time' => '2026-10-29 12:00'],
                        'airline'           => 'Finnair',
                        'flight_number'     => 'AY123',
                    ],
                    [
                        'departure_airport' => ['id' => 'HEL', 'time' => '2026-10-29 13:30'],
                        'arrival_airport'   => ['id' => 'TKU', 'time' => '2026-10-29 14:10'],
                        'airline'           => 'KLM',
                        'flight_number'     => 'KL456',
                    ],
                ],
                'price' => 160,
            ]],
        ], JSON_THROW_ON_ERROR));

        $found = $source->search(Airport::Ams, Airport::Tku, new \DateTimeImmutable('2026-10-29'));

        self::assertCount(1, $found);
        self::assertSame(Carrier::Klm, $found[0]->carrier,
            'Bij gemengde Finnair+KLM legs moet KLM worden gekozen (KLM heeft hogere prioriteit dan Finnair)');
    }

    public function test_two_leg_offer_with_unknown_airline_on_second_leg_is_skipped(): void
    {
        // Bevinding 2a: Ryanair op leg 1 moet het aanbod overslaan, ook als leg 0 geldig is.
        // Eerder: carrier=SK (van leg 0), Ryanair op leg 1 werd nooit gecheckt → doorgeglipt.
        $source = self::sourceReturning(json_encode([
            'best_flights' => [
                // Goed aanbod: SAS AMS→TKU (1 leg, door filter)
                [
                    'flights' => [[
                        'departure_airport' => ['id' => 'AMS', 'time' => '2026-10-29 09:00'],
                        'arrival_airport'   => ['id' => 'TKU', 'time' => '2026-10-29 14:10'],
                        'airline'           => 'SAS',
                        'flight_number'     => 'SK552',
                    ]],
                    'price' => 150,
                ],
                // Slecht aanbod: SAS leg 0 + Ryanair leg 1 — moet worden overgeslagen
                [
                    'flights' => [
                        [
                            'departure_airport' => ['id' => 'AMS', 'time' => '2026-10-29 09:00'],
                            'arrival_airport'   => ['id' => 'ARN', 'time' => '2026-10-29 11:00'],
                            'airline'           => 'SAS',
                            'flight_number'     => 'SK552',
                        ],
                        [
                            'departure_airport' => ['id' => 'ARN', 'time' => '2026-10-29 12:30'],
                            'arrival_airport'   => ['id' => 'TKU', 'time' => '2026-10-29 14:10'],
                            'airline'           => 'Ryanair',
                            'flight_number'     => 'FR999',
                        ],
                    ],
                    'price' => 89,
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        $found = $source->search(Airport::Ams, Airport::Tku, new \DateTimeImmutable('2026-10-29'));

        self::assertCount(1, $found,
            'Aanbod met Ryanair op leg 1 moet worden overgeslagen; eerder glipte het door via carrier=SK van leg 0');
        self::assertSame(Carrier::Sas, $found[0]->carrier);
        self::assertSame('SK552', $found[0]->segments[0]->flightNumber,
            'Alleen het zuivere SAS-aanbod (1 leg) moet overblijven');
    }

    public function test_two_leg_offer_with_unknown_airline_on_first_leg_is_skipped(): void
    {
        // Ryanair op leg 0: eerder werkte de $legs[0]-check dit al af,
        // maar de test ontbrak. Nu gedekt voor volledigheid en als mutatiedekking.
        $source = self::sourceReturning(json_encode([
            'best_flights' => [[
                'flights' => [
                    [
                        'departure_airport' => ['id' => 'AMS', 'time' => '2026-10-29 09:00'],
                        'arrival_airport'   => ['id' => 'ARN', 'time' => '2026-10-29 11:00'],
                        'airline'           => 'Ryanair',
                        'flight_number'     => 'FR001',
                    ],
                    [
                        'departure_airport' => ['id' => 'ARN', 'time' => '2026-10-29 12:30'],
                        'arrival_airport'   => ['id' => 'TKU', 'time' => '2026-10-29 14:10'],
                        'airline'           => 'SAS',
                        'flight_number'     => 'SK721',
                    ],
                ],
                'price' => 89,
            ]],
        ], JSON_THROW_ON_ERROR));

        $found = $source->search(Airport::Ams, Airport::Tku, new \DateTimeImmutable('2026-10-29'));

        self::assertSame([], $found, 'Aanbod met Ryanair op leg 0 moet worden overgeslagen');
    }

    public function test_offer_for_wrong_destination_is_skipped(): void
    {
        // Bevinding 1a: zoekroute AMS→TKU, payload bevat AMS→ARN → verwacht []
        $source = self::sourceReturning(json_encode([
            'best_flights' => [[
                'flights' => [[
                    'departure_airport' => ['id' => 'AMS', 'time' => '2026-10-29 09:00'],
                    'arrival_airport'   => ['id' => 'ARN', 'time' => '2026-10-29 11:00'],
                    'airline'           => 'SAS',
                    'flight_number'     => 'SK552',
                ]],
                'price' => 150,
            ]],
        ], JSON_THROW_ON_ERROR));

        $found = $source->search(Airport::Ams, Airport::Tku, new \DateTimeImmutable('2026-10-29'));

        self::assertSame([], $found,
            'Vlucht AMS→ARN bij een AMS→TKU-zoekopdracht moet worden overgeslagen');
    }

    public function test_offer_for_reversed_route_is_skipped(): void
    {
        // Bevinding 1b: zoekroute AMS→HEL, payload bevat HEL→AMS (omgekeerd) → verwacht []
        $source = self::sourceReturning(json_encode([
            'best_flights' => [[
                'flights' => [[
                    'departure_airport' => ['id' => 'HEL', 'time' => '2026-10-29 14:00'],
                    'arrival_airport'   => ['id' => 'AMS', 'time' => '2026-10-29 16:45'],
                    'airline'           => 'Finnair',
                    'flight_number'     => 'AY 841',
                ]],
                'price' => 80,
            ]],
        ], JSON_THROW_ON_ERROR));

        $found = $source->search(Airport::Ams, Airport::Hel, new \DateTimeImmutable('2026-10-29'));

        self::assertSame([], $found,
            'Vlucht HEL→AMS bij een AMS→HEL-zoekopdracht (omgekeerde route) moet worden overgeslagen');
    }

    public function test_non_array_flights_value_is_skipped_without_crashing(): void
    {
        // Bevinding 2: 'flights' => 'oops' mag geen PHP-warning of exception geven
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
                // Rot aanbod: 'flights' is een string, geen array
                [
                    'flights' => 'oops',
                    'price'   => 99,
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        $found = $source->search(Airport::Ams, Airport::Hel, new \DateTimeImmutable('2026-10-29'));

        self::assertCount(1, $found,
            'Non-array flights-waarde wordt overgeslagen zonder exception; het geldige aanbod blijft');
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
