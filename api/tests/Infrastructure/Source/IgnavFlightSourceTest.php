<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Source;

use App\Domain\Flight\Airport;
use App\Domain\Flight\Carrier;
use App\Domain\Flight\RoutePattern;
use App\Infrastructure\Source\IgnavFlightSource;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class IgnavFlightSourceTest extends TestCase
{
    // ---------------------------------------------------------------- helpers

    /**
     * @param array<string, mixed> $payload
     */
    private function sourceReturning(array $payload, ?callable $inspect = null): IgnavFlightSource
    {
        $client = new MockHttpClient(function (string $method, string $url, array $options) use ($payload, $inspect): MockResponse {
            $inspect?->__invoke($method, $url, $options);

            return new MockResponse(json_encode($payload, JSON_THROW_ON_ERROR));
        });

        return new IgnavFlightSource($client, 'test-key');
    }

    /**
     * De échte respons van ignav, zoals gemeten op 17 sep 2026 voor AMS→HEL.
     * Dit is het ankerpunt van deze testsuite: geen verzonnen vorm.
     *
     * @return array<string, mixed>
     */
    private function realPayload(): array
    {
        return json_decode(
            (string) file_get_contents(__DIR__.'/fixtures/ignav-ams-hel.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
    }

    /**
     * Eén geldig aanbod (Finnair AMS→HEL), met gerichte overschrijvingen.
     *
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function offer(array $overrides = []): array
    {
        $base = [
            'price' => ['amount' => 266, 'currency' => 'EUR', 'status' => 'verified'],
            'cabin_class' => 'economy',
            'requires_self_transfer' => false,
            'ignav_id' => 'test',
            'outbound' => [
                'carrier' => 'Finnair',
                'duration_minutes' => 140,
                'segments' => [
                    [
                        'marketing_carrier_code' => 'AY',
                        'flight_number' => '1302',
                        'operating_carrier_name' => 'Finnair',
                        'departure_airport' => 'AMS',
                        'departure_time_utc' => '2026-10-17T09:55:00Z',
                        'arrival_airport' => 'HEL',
                        'arrival_time_utc' => '2026-10-17T12:15:00Z',
                        'duration_minutes' => 140,
                        'aircraft' => 'Airbus A350',
                    ],
                ],
            ],
        ];

        return array_replace_recursive($base, $overrides);
    }

    /**
     * Eén aanbod met twee legs, zodat de maatschappijkeuze over gemengde legs toetsbaar is.
     *
     * @return array<string, mixed>
     */
    private function twoLegOffer(string $firstCode, string $secondCode): array
    {
        return [
            'price' => ['amount' => 442, 'currency' => 'EUR', 'status' => 'verified'],
            'requires_self_transfer' => false,
            'outbound' => [
                'segments' => [
                    [
                        'marketing_carrier_code' => $firstCode,
                        'flight_number' => '548',
                        'departure_airport' => 'AMS',
                        'departure_time_utc' => '2026-10-17T12:55:00Z',
                        'arrival_airport' => 'CPH',
                        'arrival_time_utc' => '2026-10-17T14:20:00Z',
                    ],
                    [
                        'marketing_carrier_code' => $secondCode,
                        'flight_number' => '1716',
                        'departure_airport' => 'CPH',
                        'departure_time_utc' => '2026-10-17T15:50:00Z',
                        'arrival_airport' => 'HEL',
                        'arrival_time_utc' => '2026-10-17T17:25:00Z',
                    ],
                ],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $offer
     *
     * @return list<\App\Domain\Flight\Itinerary>
     */
    private function searchWith(array $offer): array
    {
        return $this->sourceReturning(['itineraries' => [$offer]])
            ->search(Airport::Ams, Airport::Hel, new \DateTimeImmutable('2026-10-17'));
    }

    /**
     * @return list<\App\Domain\Flight\Itinerary>
     */
    private function searchRealPayload(): array
    {
        return $this->sourceReturning($this->realPayload())
            ->search(Airport::Ams, Airport::Hel, new \DateTimeImmutable('2026-10-17'));
    }

    // ------------------------------------------------- de echte respons mappen

    public function test_maps_the_real_response_to_allowed_carriers_only(): void
    {
        $itineraries = $this->searchRealPayload();

        // Acht itineraries in, twee eruit: Air Baltic (BT) en Turkish (TK) vallen af
        // op maatschappij — en dat zijn precies de twee met een overstap via RIX/IST.
        self::assertCount(6, $itineraries);

        self::assertSame(
            [Carrier::Finnair, Carrier::Finnair, Carrier::Klm, Carrier::Klm, Carrier::Klm, Carrier::Sas],
            array_map(static fn ($i) => $i->carrier, $itineraries),
        );
    }

    public function test_price_amount_is_whole_euros_not_cents(): void
    {
        $itineraries = $this->searchRealPayload();

        // Finnair staat in de fixture op amount 266 met currency EUR.
        // 266 euro is 26600 cent. Een factor-100-fout maakt hier 266 of 2660000 van.
        self::assertSame(26600, $itineraries[0]->basePrice->cents);
    }

    public function test_flight_number_gets_the_carrier_code_as_prefix(): void
    {
        $itineraries = $this->searchRealPayload();

        // ignav levert flight_number "1302" zónder maatschappijcode.
        self::assertSame('AY1302', $itineraries[0]->segments[0]->flightNumber);
    }

    public function test_times_are_read_from_the_utc_fields(): void
    {
        $itineraries = $this->searchRealPayload();

        // departure_time_local is 11:55 Amsterdam, departure_time_utc is 09:55Z.
        // Lezen we per ongeluk het lokale veld, dan staat hier 11:55.
        self::assertSame('2026-10-17T09:55:00+00:00', $itineraries[0]->segments[0]->departAt->format('c'));
        self::assertSame('2026-10-17T12:15:00+00:00', $itineraries[0]->segments[0]->arriveAt->format('c'));
    }

    public function test_via_helsinki_gets_the_train_pattern(): void
    {
        $itineraries = $this->searchRealPayload();

        // Helsinki is eindpunt van dit ticket, dus de VR-trein hoort erbij.
        self::assertSame(RoutePattern::ViaHelsinkiWithTrain, $itineraries[0]->pattern);
    }

    public function test_request_carries_the_api_key_and_the_search_parameters(): void
    {
        $seen = [];
        $source = $this->sourceReturning(
            ['itineraries' => []],
            function (string $method, string $url, array $options) use (&$seen): void {
                $seen = ['method' => $method, 'url' => $url, 'options' => $options];
            },
        );

        $source->search(Airport::Ams, Airport::Tku, new \DateTimeImmutable('2026-10-17'));

        self::assertSame('POST', $seen['method']);
        self::assertSame('https://ignav.com/api/fares/one-way', $seen['url']);

        // Symfony normaliseert headers; platslaan zodat de vorm niet uitmaakt.
        $flatHeaders = [];
        array_walk_recursive(
            $seen['options']['headers'],
            static function ($value) use (&$flatHeaders): void { $flatHeaders[] = $value; },
        );
        self::assertContains('X-Api-Key: test-key', $flatHeaders);

        $body = json_decode((string) $seen['options']['body'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(
            ['origin' => 'AMS', 'destination' => 'TKU', 'departure_date' => '2026-10-17', 'market' => 'NL'],
            $body,
        );
    }

    // ------------------------------------------------------------ afwijsregels

    public function test_self_transfer_offer_is_rejected(): void
    {
        self::assertCount(0, $this->searchWith($this->offer(['requires_self_transfer' => true])));
    }

    public function test_non_euro_currency_is_rejected(): void
    {
        self::assertCount(0, $this->searchWith($this->offer(['price' => ['currency' => 'USD']])));
    }

    public function test_zero_price_is_rejected(): void
    {
        self::assertCount(0, $this->searchWith($this->offer(['price' => ['amount' => 0]])));
    }

    public function test_negative_price_is_rejected(): void
    {
        self::assertCount(0, $this->searchWith($this->offer(['price' => ['amount' => -20]])));
    }

    public function test_non_numeric_price_is_rejected(): void
    {
        // Nederlandse locale-notatie mag niet stil als getal worden gelezen.
        self::assertCount(0, $this->searchWith($this->offer(['price' => ['amount' => '1.234,00']])));
    }

    public function test_absurd_price_is_rejected(): void
    {
        self::assertCount(0, $this->searchWith($this->offer(['price' => ['amount' => 5_000_000]])));
    }

    public function test_missing_price_object_is_rejected(): void
    {
        $offer = $this->offer();
        $offer['price'] = 'oops';

        self::assertCount(0, $this->searchWith($offer));
    }

    public function test_unknown_carrier_is_rejected(): void
    {
        // Air Baltic zat in de eerste echte respons.
        $offer = $this->offer();
        $offer['outbound']['segments'][0]['marketing_carrier_code'] = 'BT';

        self::assertCount(0, $this->searchWith($offer));
    }

    public function test_unknown_airport_is_rejected(): void
    {
        // RIX zat in de eerste echte respons als overstap.
        $offer = $this->offer();
        $offer['outbound']['segments'][0]['arrival_airport'] = 'RIX';

        self::assertCount(0, $this->searchWith($offer));
    }

    public function test_offer_that_does_not_serve_the_requested_route_is_rejected(): void
    {
        // Gevraagd AMS→HEL, geleverd AMS→ARN. Dit was Critical C1: een vlucht naar
        // Stockholm werd gepresenteerd als de goedkoopste reis naar Turku.
        $offer = $this->offer();
        $offer['outbound']['segments'][0]['arrival_airport'] = 'ARN';

        self::assertCount(0, $this->searchWith($offer));
    }

    public function test_offer_with_more_than_two_segments_is_rejected(): void
    {
        $offer = $this->offer();
        $leg = $offer['outbound']['segments'][0];
        $offer['outbound']['segments'] = [$leg, $leg, $leg];

        self::assertCount(0, $this->searchWith($offer));
    }

    public function test_empty_segment_list_is_rejected(): void
    {
        $offer = $this->offer();
        $offer['outbound']['segments'] = [];

        self::assertCount(0, $this->searchWith($offer));
    }

    public function test_non_array_segments_does_not_crash(): void
    {
        $offer = $this->offer();
        $offer['outbound']['segments'] = 'oops';

        self::assertCount(0, $this->searchWith($offer));
    }

    public function test_non_array_leg_does_not_crash(): void
    {
        $offer = $this->offer();
        $offer['outbound']['segments'] = ['oops'];

        self::assertCount(0, $this->searchWith($offer));
    }

    public function test_empty_departure_time_is_rejected(): void
    {
        // new \DateTimeImmutable('') levert "nu" op in plaats van te gooien.
        $offer = $this->offer();
        $offer['outbound']['segments'][0]['departure_time_utc'] = '';

        self::assertCount(0, $this->searchWith($offer));
    }

    public function test_unparseable_departure_time_is_rejected(): void
    {
        $offer = $this->offer();
        $offer['outbound']['segments'][0]['departure_time_utc'] = 'N/A';

        self::assertCount(0, $this->searchWith($offer));
    }

    public function test_arrival_before_departure_is_rejected(): void
    {
        $offer = $this->offer();
        $offer['outbound']['segments'][0]['arrival_time_utc'] = '2026-10-17T08:00:00Z';

        self::assertCount(0, $this->searchWith($offer));
    }

    public function test_array_flight_number_is_rejected_instead_of_becoming_the_string_Array(): void
    {
        // Minor M2 uit de oude adapter: een cast maakte hier stil "Array" van.
        $offer = $this->offer();
        $offer['outbound']['segments'][0]['flight_number'] = ['1302'];

        self::assertCount(0, $this->searchWith($offer));
    }

    // ------------------------------------------- maatschappij bij gemengde legs

    public function test_mixed_legs_pick_klm_when_it_is_on_the_first_leg(): void
    {
        // De spiegeling die eerder ontbrak: zonder deze test blijft de mutatie
        // "neem het laatste been" groen.
        $itineraries = $this->searchWith($this->twoLegOffer('KL', 'SK'));

        self::assertCount(1, $itineraries);
        self::assertSame(Carrier::Klm, $itineraries[0]->carrier);
    }

    public function test_mixed_legs_pick_klm_when_it_is_on_the_last_leg(): void
    {
        $itineraries = $this->searchWith($this->twoLegOffer('SK', 'KL'));

        self::assertCount(1, $itineraries);
        self::assertSame(Carrier::Klm, $itineraries[0]->carrier);
    }

    public function test_mixed_legs_pick_finnair_over_sas(): void
    {
        $itineraries = $this->searchWith($this->twoLegOffer('SK', 'AY'));

        self::assertCount(1, $itineraries);
        self::assertSame(Carrier::Finnair, $itineraries[0]->carrier);
    }

    public function test_one_unknown_carrier_on_either_leg_rejects_the_whole_offer(): void
    {
        self::assertCount(0, $this->searchWith($this->twoLegOffer('BT', 'AY')));
        self::assertCount(0, $this->searchWith($this->twoLegOffer('AY', 'BT')));
    }

    // ---------------------------------------------- transport en vormwijziging

    public function test_http_error_yields_no_results_and_does_not_throw(): void
    {
        $client = new MockHttpClient(new MockResponse('', ['http_code' => 429]));
        $source = new IgnavFlightSource($client, 'test-key');

        self::assertSame([], $source->search(Airport::Ams, Airport::Hel, new \DateTimeImmutable('2026-10-17')));
    }

    public function test_html_body_yields_no_results_and_does_not_throw(): void
    {
        $client = new MockHttpClient(new MockResponse('<html>rate limited</html>'));
        $source = new IgnavFlightSource($client, 'test-key');

        self::assertSame([], $source->search(Airport::Ams, Airport::Hel, new \DateTimeImmutable('2026-10-17')));
    }

    public function test_missing_itineraries_key_is_logged_as_a_shape_change(): void
    {
        $logger = new CollectingLogger();
        $client = new MockHttpClient(new MockResponse('{"error":"quota exceeded"}'));
        $source = new IgnavFlightSource($client, 'test-key', $logger);

        self::assertSame([], $source->search(Airport::Ams, Airport::Hel, new \DateTimeImmutable('2026-10-17')));
        self::assertCount(1, $logger->warnings);
        self::assertStringContainsString('itineraries-collectie', $logger->warnings[0]);
    }

    public function test_empty_itineraries_list_is_not_logged_as_an_error(): void
    {
        // Geen vluchten op deze datum is een legitiem antwoord, geen vormwijziging.
        $logger = new CollectingLogger();
        $client = new MockHttpClient(new MockResponse('{"itineraries":[]}'));
        $source = new IgnavFlightSource($client, 'test-key', $logger);

        self::assertSame([], $source->search(Airport::Ams, Airport::Hel, new \DateTimeImmutable('2026-10-17')));
        self::assertSame([], $logger->warnings);
    }

    public function test_skipped_offers_are_logged(): void
    {
        $logger = new CollectingLogger();
        $offer = $this->offer();
        $offer['outbound']['segments'][0]['marketing_carrier_code'] = 'BT';

        $client = new MockHttpClient(new MockResponse(
            json_encode(['itineraries' => [$offer]], JSON_THROW_ON_ERROR),
        ));
        $source = new IgnavFlightSource($client, 'test-key', $logger);

        self::assertSame([], $source->search(Airport::Ams, Airport::Hel, new \DateTimeImmutable('2026-10-17')));
        self::assertCount(1, $logger->warnings);
        self::assertStringContainsString('overgeslagen', $logger->warnings[0]);
    }
}

final class CollectingLogger extends AbstractLogger
{
    /** @var list<string> */
    public array $warnings = [];

    public function log($level, \Stringable|string $message, array $context = []): void
    {
        if (LogLevel::WARNING === $level) {
            $this->warnings[] = (string) $message;
        }
    }
}
