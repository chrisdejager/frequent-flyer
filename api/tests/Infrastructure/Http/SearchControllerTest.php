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

final class SearchControllerTest extends WebTestCase
{
    public function test_it_returns_priced_options_as_json(): void
    {
        $client = static::createClient();

        $flights = new InMemoryFlightPriceSource();
        $flights->give(Airport::Ams, Airport::Hel, [self::aFinnairFlight()]);
        static::getContainer()->set(FlightPriceSource::class, $flights);

        static::getContainer()->set(
            TrainPriceSource::class,
            FixedTrainPriceSource::withPrice(Money::fromEuros(22.50)),
        );

        $client->request('GET', '/api/search?date=2026-10-29&direction=nl-fi&traveller=chris');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/json');

        $body = json_decode($client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('chris', $body['traveller']['id']);
        self::assertCount(1, $body['options']);
        self::assertSame('AY', $body['options'][0]['carrier']);
        self::assertSame(13400, $body['options'][0]['cost']['basePrice']);
        self::assertFalse($body['options'][0]['cost']['indicative']);
        self::assertSame(['AY842'], $body['options'][0]['flightNumbers']);
        self::assertSame(2250, $body['options'][0]['cost']['trainCost']);
        self::assertSame(15650, $body['options'][0]['cost']['total']);
    }

    public function test_a_missing_date_is_a_bad_request(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/search?direction=nl-fi&traveller=chris');

        self::assertResponseStatusCodeSame(400);
    }

    public function test_an_unparseable_date_is_a_bad_request(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/search?date=gisteren&direction=nl-fi&traveller=chris');

        self::assertResponseStatusCodeSame(400);
    }

    public function test_an_unknown_direction_is_a_bad_request(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/search?date=2026-10-29&direction=nl-de&traveller=chris');

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

        // Een FlightPriceSource die een InvalidArgumentException gooit,
        // alsof Money of Itinerary kapotte brondata heeft gekregen.
        $broken = new class implements FlightPriceSource {
            public function search(Airport $from, Airport $to, \DateTimeImmutable $date): array
            {
                throw new \InvalidArgumentException('Bedrag mag niet negatief zijn.');
            }
        };

        static::getContainer()->set(FlightPriceSource::class, $broken);

        $client->request('GET', '/api/search?date=2026-10-29&direction=nl-fi&traveller=chris');

        self::assertResponseStatusCodeSame(500,
            'Een \InvalidArgumentException uit de databron hoort een 500 te zijn, niet een 400.');
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

        $client->request('GET', '/api/search?date=2026-10-29&direction=nl-fi&traveller=chris');

        $body = json_decode($client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertArrayHasKey('trainCostKnown', $body['options'][0]['cost']);
        self::assertTrue($body['options'][0]['cost']['trainCostKnown']);
    }

    public function test_unknown_train_price_sets_trainCostKnown_false_and_indicative_true(): void
    {
        $client = static::createClient();

        $flights = new InMemoryFlightPriceSource();
        $flights->give(Airport::Ams, Airport::Hel, [self::aFinnairFlight()]);
        static::getContainer()->set(FlightPriceSource::class, $flights);

        static::getContainer()->set(
            TrainPriceSource::class,
            FixedTrainPriceSource::withUnknownPrice(),
        );

        $client->request('GET', '/api/search?date=2026-10-29&direction=nl-fi&traveller=chris');

        $body = json_decode($client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertFalse($body['options'][0]['cost']['trainCostKnown']);
        self::assertTrue($body['options'][0]['cost']['indicative']);
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
