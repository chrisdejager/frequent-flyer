<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Source;

use App\Domain\Flight\Airport;
use App\Domain\Flight\Route;
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
     * The real response from ignav, as measured on 17 Sep 2026 for AMS→HEL.
     * This is the anchor point of this test suite: not invented form.
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
     * One valid offer (Finnair AMS→HEL), with targeted overrides.
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
     * One offer with two legs, so carrier choice over mixed legs is testable.
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
            ->search([new Route(Airport::Ams, Airport::Hel)], new \DateTimeImmutable('2026-10-17'));
    }

    /**
     * @return list<\App\Domain\Flight\Itinerary>
     */
    private function searchRealPayload(): array
    {
        return $this->sourceReturning($this->realPayload())
            ->search([new Route(Airport::Ams, Airport::Hel)], new \DateTimeImmutable('2026-10-17'));
    }

    // ------------------------------------------------- map the real response

    public function test_maps_the_real_response_to_allowed_carriers_only(): void
    {
        $itineraries = $this->searchRealPayload();

        // Eight itineraries in, two out: Air Baltic (BT) and Turkish (TK) fall away
        // on carrier — and those are exactly the two with a connection via RIX/IST.
        self::assertCount(6, $itineraries);

        self::assertSame(
            [Carrier::Finnair, Carrier::Finnair, Carrier::Klm, Carrier::Klm, Carrier::Klm, Carrier::Sas],
            array_map(static fn ($i) => $i->carrier, $itineraries),
        );
    }

    public function test_price_amount_is_whole_euros_not_cents(): void
    {
        $itineraries = $this->searchRealPayload();

        // Finnair in the fixture is at amount 266 with currency EUR.
        // 266 euros is 26600 cents. A factor-100 error makes this 266 or 2660000.
        self::assertSame(26600, $itineraries[0]->basePrice->cents);
    }

    public function test_flight_number_gets_the_carrier_code_as_prefix(): void
    {
        $itineraries = $this->searchRealPayload();

        // ignav delivers flight_number "1302" without airline code.
        self::assertSame('AY1302', $itineraries[0]->segments[0]->flightNumber);
    }

    public function test_times_are_read_from_the_utc_fields(): void
    {
        $itineraries = $this->searchRealPayload();

        // departure_time_local is 11:55 Amsterdam, departure_time_utc is 09:55Z.
        // If we accidentally read the local field, 11:55 shows here.
        self::assertSame('2026-10-17T09:55:00+00:00', $itineraries[0]->segments[0]->departAt->format('c'));
        self::assertSame('2026-10-17T12:15:00+00:00', $itineraries[0]->segments[0]->arriveAt->format('c'));
    }

    public function test_it_carries_the_ignav_id_as_booking_ref(): void
    {
        $itineraries = $this->searchRealPayload();

        // The first allowed option in the fixture is Finnair (BT before it falls away);
        // a shifted index gives the id of Air Baltic or the second Finnair here.
        self::assertSame('0ea5aa41342f00000056346ef96d746d', $itineraries[0]->bookingRef);
        self::assertSame('f60792e7dede000000cac361c3234ba8', $itineraries[2]->bookingRef);
    }

    public function test_a_missing_ignav_id_gives_a_null_booking_ref(): void
    {
        $offer = $this->offer();
        unset($offer['ignav_id']);

        $itineraries = $this->searchWith($offer);

        self::assertCount(1, $itineraries);
        self::assertNull($itineraries[0]->bookingRef);
    }

    public function test_a_non_string_ignav_id_gives_a_null_booking_ref(): void
    {
        $itineraries = $this->searchWith($this->offer(['ignav_id' => ['x']]));

        self::assertCount(1, $itineraries);
        self::assertNull($itineraries[0]->bookingRef);
    }

    public function test_via_helsinki_gets_the_train_pattern(): void
    {
        $itineraries = $this->searchRealPayload();

        // Helsinki is the endpoint of this ticket, so the VR train belongs.
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

        $source->search([new Route(Airport::Ams, Airport::Tku)], new \DateTimeImmutable('2026-10-17'));

        self::assertSame('POST', $seen['method']);
        self::assertSame('https://ignav.com/api/fares/one-way', $seen['url']);

        // Symfony normalizes headers; flatten so form doesn't matter.
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

    // -------------------------------------------------------- several routes

    public function test_all_routes_are_requested_before_the_first_answer_is_read(): void
    {
        // Measured 20 Oct 2026: one ignav call takes ~1.6 s. If the adapter waits
        // for the HEL answer before sending the TKU request, the two add up.
        $requested = 0;
        $requestedWhenFirstRead = null;

        $client = new MockHttpClient(function () use (&$requested, &$requestedWhenFirstRead): MockResponse {
            ++$requested;

            return new MockResponse((static function () use (&$requested, &$requestedWhenFirstRead): \Generator {
                $requestedWhenFirstRead ??= $requested;
                yield '{"itineraries":[]}';
            })());
        });

        (new IgnavFlightSource($client, 'test-key'))->search(
            [new Route(Airport::Ams, Airport::Hel), new Route(Airport::Ams, Airport::Tku)],
            new \DateTimeImmutable('2026-10-17'),
        );

        self::assertSame(2, $requestedWhenFirstRead, 'both requests must be out before either answer is read');
    }

    public function test_results_of_all_routes_are_combined(): void
    {
        $client = new MockHttpClient($this->answerPerDestination([
            'HEL' => new MockResponse(json_encode(['itineraries' => [$this->offer()]], JSON_THROW_ON_ERROR)),
            'TKU' => new MockResponse(json_encode(['itineraries' => [$this->tkuOffer()]], JSON_THROW_ON_ERROR)),
        ]));

        $found = (new IgnavFlightSource($client, 'test-key'))->search(
            [new Route(Airport::Ams, Airport::Hel), new Route(Airport::Ams, Airport::Tku)],
            new \DateTimeImmutable('2026-10-17'),
        );

        self::assertSame(
            [Airport::Hel, Airport::Tku],
            array_map(static fn ($i) => $i->destination(), $found),
        );
    }

    public function test_a_failing_route_does_not_drop_the_other(): void
    {
        $client = new MockHttpClient($this->answerPerDestination([
            'HEL' => new MockResponse('', ['http_code' => 429]),
            'TKU' => new MockResponse(json_encode(['itineraries' => [$this->tkuOffer()]], JSON_THROW_ON_ERROR)),
        ]));

        $found = (new IgnavFlightSource($client, 'test-key'))->search(
            [new Route(Airport::Ams, Airport::Hel), new Route(Airport::Ams, Airport::Tku)],
            new \DateTimeImmutable('2026-10-17'),
        );

        self::assertCount(1, $found);
        self::assertSame(Airport::Tku, $found[0]->destination());
    }

    /**
     * @param array<string, MockResponse> $byDestination
     */
    private function answerPerDestination(array $byDestination): \Closure
    {
        return static function (string $method, string $url, array $options) use ($byDestination): MockResponse {
            $body = json_decode((string) $options['body'], true, 512, JSON_THROW_ON_ERROR);

            return $byDestination[$body['destination']];
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function tkuOffer(): array
    {
        $offer = $this->offer();
        $offer['outbound']['segments'][0]['arrival_airport'] = 'TKU';

        return $offer;
    }

    // ------------------------------------------------------------ observed_at

    public function test_observed_at_is_carried_onto_every_itinerary(): void
    {
        $found = $this->sourceReturning([
            'observed_at' => '2026-10-20T19:05:53.833840Z',
            'itineraries' => [$this->offer(), $this->offer()],
        ])->search([new Route(Airport::Ams, Airport::Hel)], new \DateTimeImmutable('2026-10-17'));

        self::assertCount(2, $found);
        foreach ($found as $itinerary) {
            self::assertSame('2026-10-20T19:05:53+00:00', $itinerary->observedAt?->format(\DATE_ATOM));
        }
    }

    public function test_missing_or_unreadable_observed_at_is_null_not_now(): void
    {
        // new \DateTimeImmutable('') would silently mean "now" — exactly the
        // false freshness this field exists to expose.
        foreach ([null, '', 'yesterday', '2026-10-20 19:05', 12345] as $raw) {
            $found = $this->sourceReturning(['observed_at' => $raw, 'itineraries' => [$this->offer()]])
                ->search([new Route(Airport::Ams, Airport::Hel)], new \DateTimeImmutable('2026-10-17'));

            self::assertCount(1, $found, var_export($raw, true));
            self::assertNull($found[0]->observedAt, var_export($raw, true));
        }
    }

    // ------------------------------------------------------------ rejection rules

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
        // Dutch locale notation must not silently be read as a number.
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
        // Air Baltic was in the first real response.
        $offer = $this->offer();
        $offer['outbound']['segments'][0]['marketing_carrier_code'] = 'BT';

        self::assertCount(0, $this->searchWith($offer));
    }

    public function test_unknown_airport_is_rejected(): void
    {
        // RIX was in the first real response as a connection.
        $offer = $this->offer();
        $offer['outbound']['segments'][0]['arrival_airport'] = 'RIX';

        self::assertCount(0, $this->searchWith($offer));
    }

    public function test_offer_that_does_not_serve_the_requested_route_is_rejected(): void
    {
        // Requested AMS→HEL, delivered AMS→ARN. This was Critical C1: a flight to
        // Stockholm was presented as the cheapest trip to Turku.
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
        // new \DateTimeImmutable('') returns "now" instead of throwing.
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
        // Minor M2 from the old adapter: a cast made "Array" silently here.
        $offer = $this->offer();
        $offer['outbound']['segments'][0]['flight_number'] = ['1302'];

        self::assertCount(0, $this->searchWith($offer));
    }

    // ------------------------------------------- airline with mixed legs

    public function test_mixed_legs_pick_klm_when_it_is_on_the_first_leg(): void
    {
        // The mirror that was missing before: without this test, the mutation
        // "take the last leg" remains green.
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

    // ---------------------------------------------- transport and reshaping

    public function test_http_error_yields_no_results_and_does_not_throw(): void
    {
        $client = new MockHttpClient(new MockResponse('', ['http_code' => 429]));
        $source = new IgnavFlightSource($client, 'test-key');

        self::assertSame([], $source->search([new Route(Airport::Ams, Airport::Hel)], new \DateTimeImmutable('2026-10-17')));
    }

    public function test_html_body_yields_no_results_and_does_not_throw(): void
    {
        $client = new MockHttpClient(new MockResponse('<html>rate limited</html>'));
        $source = new IgnavFlightSource($client, 'test-key');

        self::assertSame([], $source->search([new Route(Airport::Ams, Airport::Hel)], new \DateTimeImmutable('2026-10-17')));
    }

    public function test_missing_itineraries_key_is_logged_as_a_shape_change(): void
    {
        $logger = new CollectingLogger();
        $client = new MockHttpClient(new MockResponse('{"error":"quota exceeded"}'));
        $source = new IgnavFlightSource($client, 'test-key', $logger);

        self::assertSame([], $source->search([new Route(Airport::Ams, Airport::Hel)], new \DateTimeImmutable('2026-10-17')));
        self::assertCount(1, $logger->warnings);
        self::assertStringContainsString('itineraries', $logger->warnings[0]);
    }

    public function test_empty_itineraries_list_is_not_logged_as_an_error(): void
    {
        // No flights on this date is a legitimate answer, not a shape change.
        $logger = new CollectingLogger();
        $client = new MockHttpClient(new MockResponse('{"itineraries":[]}'));
        $source = new IgnavFlightSource($client, 'test-key', $logger);

        self::assertSame([], $source->search([new Route(Airport::Ams, Airport::Hel)], new \DateTimeImmutable('2026-10-17')));
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

        self::assertSame([], $source->search([new Route(Airport::Ams, Airport::Hel)], new \DateTimeImmutable('2026-10-17')));
        self::assertCount(1, $logger->warnings);
        self::assertStringContainsString('skipped', $logger->warnings[0]);
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
