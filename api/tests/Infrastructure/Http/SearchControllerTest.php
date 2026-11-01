<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Http;

use App\Domain\Flight\Airport;
use App\Domain\Flight\Carrier;
use App\Domain\Flight\Itinerary;
use App\Domain\Flight\Money;
use App\Domain\Flight\RoutePattern;
use App\Domain\Flight\Segment;
use App\Domain\Port\FlightPriceSource;
use App\Tests\Double\InMemoryFlightPriceSource;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class SearchControllerTest extends WebTestCase
{
    public function test_it_returns_priced_options_as_json(): void
    {
        $client = static::createClient();

        $flights = new InMemoryFlightPriceSource();
        $flights->give(Airport::Ams, Airport::Hel, [self::aFinnairFlight()]);
        static::getContainer()->set(FlightPriceSource::class, $flights);

        $client->request('GET', '/api/search?date=2026-10-29&direction=nl-fi&traveller=default');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/json');

        $body = json_decode($client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('default', $body['traveller']['id']);
        self::assertCount(1, $body['options']);
        self::assertSame('AY', $body['options'][0]['carrier']);
        self::assertSame('helsinki', $body['options'][0]['pattern']);
        self::assertSame(['AY842'], $body['options'][0]['flightNumbers']);
        self::assertSame(
            ['basePrice' => 13400, 'total' => 13400, 'totalFormatted' => '€ 134,00'],
            $body['options'][0]['cost'],
            'the cost carries the flight price only: no transfer fields',
        );
    }

    public function test_response_includes_origin_and_destination_so_the_frontend_can_localise_times(): void
    {
        // The times in the response are UTC instants; without origin and
        // destination airport the frontend cannot show them in the correct local
        // time zone (AMS != HEL != TKU time).
        $client = static::createClient();

        $flights = new InMemoryFlightPriceSource();
        $flights->give(Airport::Ams, Airport::Hel, [self::aFinnairFlight()]);
        static::getContainer()->set(FlightPriceSource::class, $flights);

        $client->request('GET', '/api/search?date=2026-10-29&direction=nl-fi&traveller=default');

        $body = json_decode($client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('AMS', $body['options'][0]['origin']);
        self::assertSame('HEL', $body['options'][0]['destination']);
    }

    public function test_a_missing_date_is_a_bad_request(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/search?direction=nl-fi&traveller=default');

        self::assertResponseStatusCodeSame(400);
    }

    public function test_an_unparseable_date_is_a_bad_request(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/search?date=gisteren&direction=nl-fi&traveller=default');

        self::assertResponseStatusCodeSame(400);
    }

    public function test_an_unknown_direction_is_a_bad_request(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/search?date=2026-10-29&direction=nl-de&traveller=default');

        self::assertResponseStatusCodeSame(400);
    }

    public function test_an_unknown_traveller_is_a_bad_request(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/search?date=2026-10-29&direction=nl-fi&traveller=buurman');

        self::assertResponseStatusCodeSame(400);
    }

    public function test_a_data_source_error_is_a_server_error(): void
    {
        $client = static::createClient();

        // A FlightPriceSource that throws InvalidArgumentException,
        // as if Money or Itinerary received corrupt source data.
        $broken = new class implements FlightPriceSource {
            public function search(array $routes, \DateTimeImmutable $date): array
            {
                throw new \InvalidArgumentException('Amount must not be negative.');
            }
        };

        static::getContainer()->set(FlightPriceSource::class, $broken);

        $client->request('GET', '/api/search?date=2026-10-29&direction=nl-fi&traveller=default');

        self::assertResponseStatusCodeSame(500,
            'An InvalidArgumentException from the data source should be 500, not 400.');
    }

    public function test_options_are_ranked_cheapest_first_through_the_real_wiring(): void
    {
        // Through the real Symfony wiring: an earlier sort error was invisible in
        // unit tests. The source returns the Helsinki route first, so the
        // cheaper SAS through ticket (€140) can only lead if the handler sorts.
        $client = static::createClient();

        // Finnair to HEL: €150
        $helFlight = new Itinerary(
            Carrier::Finnair,
            [new Segment(
                Airport::Ams,
                Airport::Hel,
                new \DateTimeImmutable('2026-10-29 13:00'),
                new \DateTimeImmutable('2026-10-29 16:45'),
                'AY842',
            )],
            Money::fromEuros(150),
            RoutePattern::Helsinki,
        );

        // SAS through ticket to TKU: €140
        $tkuFlight = new Itinerary(
            Carrier::Sas,
            [
                new Segment(
                    Airport::Ams,
                    Airport::Arn,
                    new \DateTimeImmutable('2026-10-29 09:00'),
                    new \DateTimeImmutable('2026-10-29 11:00'),
                    'SK552',
                ),
                new Segment(
                    Airport::Arn,
                    Airport::Tku,
                    new \DateTimeImmutable('2026-10-29 12:30'),
                    new \DateTimeImmutable('2026-10-29 14:10'),
                    'SK721',
                ),
            ],
            Money::fromEuros(140),
            RoutePattern::ThroughTicket,
        );

        $flights = new InMemoryFlightPriceSource();
        $flights->give(Airport::Ams, Airport::Hel, [$helFlight]);
        $flights->give(Airport::Ams, Airport::Tku, [$tkuFlight]);
        static::getContainer()->set(FlightPriceSource::class, $flights);

        $client->request('GET', '/api/search?date=2026-10-29&direction=nl-fi&traveller=default');

        self::assertResponseIsSuccessful();

        $body = json_decode($client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertCount(2, $body['options']);
        self::assertSame('SK', $body['options'][0]['carrier'], 'SAS through ticket, €140, is the cheapest.');
        self::assertSame('AY', $body['options'][1]['carrier'], 'Finnair to HEL, €150, comes second.');
    }

    public function test_each_option_exposes_its_booking_ref(): void
    {
        $client = static::createClient();

        $withRef = new Itinerary(
            Carrier::Finnair,
            [new Segment(
                Airport::Ams,
                Airport::Hel,
                new \DateTimeImmutable('2026-10-29 13:00'),
                new \DateTimeImmutable('2026-10-29 16:45'),
                'AY842',
            )],
            Money::fromEuros(120), // cheaper, so guaranteed options[0]
            RoutePattern::Helsinki,
            '0ea5aa41342f00000056346ef96d746d',
        );

        $flights = new InMemoryFlightPriceSource();
        $flights->give(Airport::Ams, Airport::Hel, [$withRef, self::aFinnairFlight()]);
        static::getContainer()->set(FlightPriceSource::class, $flights);

        $client->request('GET', '/api/search?date=2026-10-29&direction=nl-fi&traveller=default');

        $body = json_decode($client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('0ea5aa41342f00000056346ef96d746d', $body['options'][0]['bookingRef']);
        // Without a ref the key is present anyway, as null: the frontend then shows no button.
        self::assertArrayHasKey('bookingRef', $body['options'][1]);
        self::assertNull($body['options'][1]['bookingRef']);
    }

    public function test_each_option_exposes_when_the_source_observed_its_price(): void
    {
        $client = static::createClient();

        $observed = new Itinerary(
            Carrier::Finnair,
            [new Segment(
                Airport::Ams,
                Airport::Hel,
                new \DateTimeImmutable('2026-10-29 13:00'),
                new \DateTimeImmutable('2026-10-29 16:45'),
                'AY842',
            )],
            Money::fromEuros(120), // cheaper, so guaranteed options[0]
            RoutePattern::Helsinki,
            observedAt: new \DateTimeImmutable('2026-10-20T19:05:53Z'),
        );

        $flights = new InMemoryFlightPriceSource();
        $flights->give(Airport::Ams, Airport::Hel, [$observed, self::aFinnairFlight()]);
        static::getContainer()->set(FlightPriceSource::class, $flights);

        $client->request('GET', '/api/search?date=2026-10-29&direction=nl-fi&traveller=default');

        $body = json_decode($client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('2026-10-20T19:05:53+00:00', $body['options'][0]['observedAt']);
        // Unknown is null, never "now": a made-up timestamp would look fresh.
        self::assertArrayHasKey('observedAt', $body['options'][1]);
        self::assertNull($body['options'][1]['observedAt']);
    }

    /**
     * Through the real wiring: the key comes from the environment via
     * services.yaml, so a typo in the env name or a lost argument shows up here.
     */
    public function test_a_missing_ignav_key_gives_503_with_a_clear_message(): void
    {
        $originalKey = $_ENV['IGNAV_API_KEY'] ?? null;
        $_ENV['IGNAV_API_KEY'] = '';
        $_SERVER['IGNAV_API_KEY'] = '';

        try {
            $client = static::createClient();

            // Without the guard the real adapter would send requests: they must
            // land here, never on the network.
            $calls = 0;
            static::getContainer()->set('http_client', new MockHttpClient(function () use (&$calls): MockResponse {
                ++$calls;

                return new MockResponse('', ['http_code' => 401]);
            }));

            $client->request('GET', '/api/search?date=2026-10-29&direction=nl-fi&traveller=default');

            self::assertResponseStatusCodeSame(503);
            $body = json_decode($client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
            self::assertStringContainsString('IGNAV_API_KEY', $body['error']);
            self::assertSame(0, $calls);
        } finally {
            // Restore, don't delete: see tearDown.
            $_ENV['IGNAV_API_KEY'] = $originalKey ?? '';
            $_SERVER['IGNAV_API_KEY'] = $originalKey ?? '';
        }
    }

    public function test_a_rejected_ignav_key_gives_503_with_a_clear_message(): void
    {
        $originalKey = $_ENV['IGNAV_API_KEY'] ?? null;
        $_ENV['IGNAV_API_KEY'] = 'wrong-key';
        $_SERVER['IGNAV_API_KEY'] = 'wrong-key';

        try {
            $client = static::createClient();

            $sentKeys = [];
            static::getContainer()->set('http_client', new MockHttpClient(function (string $method, string $url, array $options) use (&$sentKeys): MockResponse {
                $sentKeys[] = $options['normalized_headers']['x-api-key'][0] ?? null;

                return new MockResponse('{"detail": "Invalid API key"}', ['http_code' => 401]);
            }));

            $client->request('GET', '/api/search?date=2026-10-29&direction=nl-fi&traveller=default');

            self::assertResponseStatusCodeSame(503);
            $body = json_decode($client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
            self::assertStringContainsString('ignav rejected IGNAV_API_KEY', $body['error']);
            // The key from the environment is the one that was sent.
            self::assertNotEmpty($sentKeys);
            self::assertSame(['X-Api-Key: wrong-key'], array_values(array_unique($sentKeys)));
        } finally {
            $_ENV['IGNAV_API_KEY'] = $originalKey ?? '';
            $_SERVER['IGNAV_API_KEY'] = $originalKey ?? '';
        }
    }

    private static function aFinnairFlight(): Itinerary
    {
        return new Itinerary(
            Carrier::Finnair,
            [new Segment(
                Airport::Ams,
                Airport::Hel,
                new \DateTimeImmutable('2026-10-29 13:00'),
                new \DateTimeImmutable('2026-10-29 16:45'),
                'AY842',
            )],
            Money::fromEuros(134),
            RoutePattern::Helsinki,
        );
    }
}
