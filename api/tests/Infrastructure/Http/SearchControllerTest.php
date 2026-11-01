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
use App\Domain\Port\TrainPriceSource;
use App\Tests\Double\FixedTrainPriceSource;
use App\Tests\Double\InMemoryFlightPriceSource;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class SearchControllerTest extends WebTestCase
{
    private ?string $originalTrainCostEnabled = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalTrainCostEnabled = $_ENV['TRAIN_COST_ENABLED'] ?? null;
    }

    protected function tearDown(): void
    {
        // Restore, don't delete: %env(bool:TRAIN_COST_ENABLED)% throws an
        // EnvNotFoundException if the variable disappears, and then every
        // subsequent test silently gets a 500 instead of its own result.
        if (null === $this->originalTrainCostEnabled) {
            unset($_ENV['TRAIN_COST_ENABLED'], $_SERVER['TRAIN_COST_ENABLED']);
        } else {
            $_ENV['TRAIN_COST_ENABLED'] = $this->originalTrainCostEnabled;
            $_SERVER['TRAIN_COST_ENABLED'] = $this->originalTrainCostEnabled;
        }

        parent::tearDown();
    }

    /**
     * Explicitly enables the HEL–TKU transfer for tests about train semantics.
     *
     * Without this those tests silently measure something else as soon as TRAIN_COST_ENABLED
     * changes in .env — and the semantics they establish (unknown train price → at the end,
     * no estimate) must keep working for when the toggle turns back on.
     * Must come before createClient(): after that the container is already built.
     */
    private static function enableTrainCost(): void
    {
        $_ENV['TRAIN_COST_ENABLED'] = '1';
        $_SERVER['TRAIN_COST_ENABLED'] = '1';
    }

    public function test_it_returns_priced_options_as_json(): void
    {
        self::enableTrainCost();
        $client = static::createClient();

        $flights = new InMemoryFlightPriceSource();
        $flights->give(Airport::Ams, Airport::Hel, [self::aFinnairFlight()]);
        static::getContainer()->set(FlightPriceSource::class, $flights);

        static::getContainer()->set(
            TrainPriceSource::class,
            FixedTrainPriceSource::withPrice(Money::fromEuros(22.50)),
        );

        $client->request('GET', '/api/search?date=2026-10-29&direction=nl-fi&traveller=default');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/json');

        $body = json_decode($client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('default', $body['traveller']['id']);
        self::assertCount(1, $body['options']);
        self::assertSame('AY', $body['options'][0]['carrier']);
        self::assertSame(13400, $body['options'][0]['cost']['basePrice']);
        self::assertFalse($body['options'][0]['cost']['indicative']);
        self::assertSame(['AY842'], $body['options'][0]['flightNumbers']);
        self::assertSame(2250, $body['options'][0]['cost']['trainCost']);
        self::assertSame(15650, $body['options'][0]['cost']['total']);
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

    public function test_trainCostKnown_is_present_in_json_response(): void
    {
        $client = static::createClient();

        $flights = new InMemoryFlightPriceSource();
        $flights->give(Airport::Ams, Airport::Hel, [self::aFinnairFlight()]);
        static::getContainer()->set(FlightPriceSource::class, $flights);

        static::getContainer()->set(
            TrainPriceSource::class,
            FixedTrainPriceSource::withPrice(Money::fromEuros(22.50)),
        );

        $client->request('GET', '/api/search?date=2026-10-29&direction=nl-fi&traveller=default');

        $body = json_decode($client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertArrayHasKey('trainCostKnown', $body['options'][0]['cost']);
        self::assertTrue($body['options'][0]['cost']['trainCostKnown']);
    }

    public function test_with_the_train_switched_off_hel_options_report_an_incomplete_total(): void
    {
        // The other toggle state, through the real container. No
        // enableTrainCost(): this is the configuration that's actually running.
        $client = static::createClient();

        $flights = new InMemoryFlightPriceSource();
        $flights->give(Airport::Ams, Airport::Hel, [self::aFinnairFlight()]);
        static::getContainer()->set(FlightPriceSource::class, $flights);

        static::getContainer()->set(
            TrainPriceSource::class,
            FixedTrainPriceSource::withPrice(Money::fromEuros(22.50)),
        );

        $client->request('GET', '/api/search?date=2026-10-29&direction=nl-fi&traveller=default');

        $body = json_decode($client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $cost = $body['options'][0]['cost'];

        self::assertSame(0, $cost['trainCost'], 'the transfer does not count');
        self::assertSame(13400, $cost['total'], 'total is only the ticket price');
        self::assertTrue($cost['trainCostKnown'], 'the option continues to participate in sorting');
        self::assertFalse($cost['trainCostIncluded'],
            'the frontend must be able to report that the transfer HEL→TKU is missing — otherwise this is an invisible zero');
    }

    public function test_with_the_train_switched_off_the_train_source_is_never_called(): void
    {
        // Money, not just speed: ApifyTrainPriceSource starts a synchronous
        // actor run, and credits are ~20-50 runs per month. Fetching a result
        // that the calculator then discards is one too many.
        $client = static::createClient();

        $flights = new InMemoryFlightPriceSource();
        $flights->give(Airport::Ams, Airport::Hel, [self::aFinnairFlight()]);
        static::getContainer()->set(FlightPriceSource::class, $flights);

        $trains = FixedTrainPriceSource::withPrice(Money::fromEuros(22.50));
        static::getContainer()->set(TrainPriceSource::class, $trains);

        $client->request('GET', '/api/search?date=2026-10-29&direction=nl-fi&traveller=default');

        self::assertResponseIsSuccessful();
        self::assertSame(0, $trains->callCount,
            'the train source must not be called when the transfer is off');
    }

    public function test_with_the_train_switched_on_the_train_source_is_called(): void
    {
        // The other side of the same seam: the guard must not
        // permanently disable fetching if the switch comes back on.
        self::enableTrainCost();
        $client = static::createClient();

        $flights = new InMemoryFlightPriceSource();
        $flights->give(Airport::Ams, Airport::Hel, [self::aFinnairFlight()]);
        static::getContainer()->set(FlightPriceSource::class, $flights);

        $trains = FixedTrainPriceSource::withPrice(Money::fromEuros(22.50));
        static::getContainer()->set(TrainPriceSource::class, $trains);

        $client->request('GET', '/api/search?date=2026-10-29&direction=nl-fi&traveller=default');

        self::assertResponseIsSuccessful();
        self::assertSame(1, $trains->callCount,
            'with the transfer on, the train price should be fetched once');
    }

    public function test_unknown_train_price_sets_trainCostKnown_false_and_indicative_true(): void
    {
        self::enableTrainCost();
        $client = static::createClient();

        $flights = new InMemoryFlightPriceSource();
        $flights->give(Airport::Ams, Airport::Hel, [self::aFinnairFlight()]);
        static::getContainer()->set(FlightPriceSource::class, $flights);

        static::getContainer()->set(
            TrainPriceSource::class,
            FixedTrainPriceSource::withUnknownPrice(),
        );

        $client->request('GET', '/api/search?date=2026-10-29&direction=nl-fi&traveller=default');

        $body = json_decode($client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertFalse($body['options'][0]['cost']['trainCostKnown']);
        self::assertTrue($body['options'][0]['cost']['indicative']);
    }

    public function test_option_with_unknown_train_price_ranks_last_without_estimate(): void
    {
        // Finding 1: no estimate, no assumption.
        // Options with unknown train price are ranked at the end (trainCostKnown=false),
        // even if their displayed total is lower than a ranked option.
        // SAS through-ticket €150 (trainCostKnown=true) comes before Finnair via HEL €140
        // (trainCostKnown=false), even though €140 is numerically lower than €150.
        // This test goes through the real Symfony wiring; the two previous Criticals
        // (factor-100 and sort error) were invisible in unit tests.
        self::enableTrainCost();
        $client = static::createClient();

        // Finnair via HEL: base price €140, unknown train price → at the end
        $helFlight = new Itinerary(
            Carrier::Finnair,
            [new Segment(
                Airport::Ams,
                Airport::Hel,
                new \DateTimeImmutable('2026-10-29 13:00'),
                new \DateTimeImmutable('2026-10-29 16:45'),
                'AY842',
            )],
            Money::fromEuros(140),
            RoutePattern::ViaHelsinkiWithTrain,
        );

        // SAS through-ticket to TKU: base price €150, no train → ranked
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
            Money::fromEuros(150),
            RoutePattern::ThroughTicket,
        );

        $flights = new InMemoryFlightPriceSource();
        $flights->give(Airport::Ams, Airport::Hel, [$helFlight]);
        $flights->give(Airport::Ams, Airport::Tku, [$tkuFlight]);
        static::getContainer()->set(FlightPriceSource::class, $flights);

        // Unknown train price: Finnair option has trainCostKnown=false
        static::getContainer()->set(
            TrainPriceSource::class,
            FixedTrainPriceSource::withUnknownPrice(),
        );

        $client->request('GET', '/api/search?date=2026-10-29&direction=nl-fi&traveller=default');

        self::assertResponseIsSuccessful();

        $body = json_decode($client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertCount(2, $body['options']);
        // SAS comes first: trainCostKnown=true, ranked on €150
        self::assertSame('SK', $body['options'][0]['carrier'],
            'SAS through-ticket (trainCostKnown=true, €150) comes first — ranked on total price.');
        // Finnair comes second: trainCostKnown=false → at the end, no estimate
        self::assertSame('AY', $body['options'][1]['carrier'],
            'Finnair via HEL (trainCostKnown=false, €140) comes last — not ranked.');
        self::assertFalse($body['options'][1]['cost']['trainCostKnown'],
            'Finnair option has trainCostKnown=false in the JSON response.');
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
            RoutePattern::ViaHelsinkiWithTrain,
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
            RoutePattern::ViaHelsinkiWithTrain,
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
            RoutePattern::ViaHelsinkiWithTrain,
        );
    }
}
