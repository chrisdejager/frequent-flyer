<?php

declare(strict_types=1);

namespace App\Tests\Application\SearchFlights;

use App\Application\SearchFlights\SearchFlightsHandler;
use App\Application\SearchFlights\SearchFlightsQuery;
use App\Domain\Flight\Airport;
use App\Domain\Flight\Carrier;
use App\Domain\Flight\Direction;
use App\Domain\Flight\Itinerary;
use App\Domain\Flight\Money;
use App\Domain\Flight\RoutePattern;
use App\Domain\Flight\Segment;
use App\Domain\Pricing\TotalCostCalculator;
use App\Infrastructure\Persistence\NullPriceHistory;
use App\Infrastructure\Source\IgnavFlightSource;
use App\Tests\Double\FixedTrainPriceSource;
use App\Tests\Double\InMemoryFlightPriceSource;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class SearchFlightsHandlerTest extends TestCase
{
    public function test_it_returns_options_cheapest_first_including_the_train(): void
    {
        $flights = new InMemoryFlightPriceSource();
        $flights->give(Airport::Ams, Airport::Hel, [
            self::viaHel(Carrier::Finnair, 134, '13:00', '16:45'),
        ]);
        $flights->give(Airport::Ams, Airport::Tku, [
            self::throughTicket(Carrier::Sas, 150),
        ]);

        $result = $this->handler($flights, FixedTrainPriceSource::withPrice(Money::fromEuros(22.50)))(
            new SearchFlightsQuery(new \DateTimeImmutable('2026-10-29'), Direction::NlToFi, 'chris'),
        );

        self::assertCount(2, $result->options);
        self::assertSame(15000, $result->options[0]->cost->total()->cents, 'SAS doorgaand: €150');
        self::assertSame(15650, $result->options[1]->cost->total()->cents, 'Finnair €134 + trein €22,50');
    }

    public function test_options_that_break_the_rules_are_dropped(): void
    {
        $flights = new InMemoryFlightPriceSource();
        $flights->give(Airport::Ams, Airport::Hel, [
            self::viaHel(Carrier::Finnair, 134, '13:00', '16:45'),
            self::viaHel(Carrier::Finnair, 118, '18:30', '22:15'),
        ]);

        $result = $this->handler($flights, FixedTrainPriceSource::withPrice(Money::fromEuros(22.50)))(
            new SearchFlightsQuery(new \DateTimeImmutable('2026-10-29'), Direction::NlToFi, 'chris'),
        );

        self::assertCount(1, $result->options, 'de goedkopere avondvlucht valt af op aankomsttijd');
        self::assertSame(13400, $result->options[0]->itinerary->basePrice->cents);
    }

    public function test_chris_and_partner_get_the_same_total_on_finnair(): void
    {
        // Bagagekosten zijn eruit (versimpeling): de reiziger mag het totaal niet
        // meer beïnvloeden.
        $flights = new InMemoryFlightPriceSource();
        $flights->give(Airport::Ams, Airport::Hel, [self::viaHel(Carrier::Finnair, 134, '13:00', '16:45')]);

        $handler = $this->handler($flights, FixedTrainPriceSource::withPrice(Money::fromEuros(22.50)));

        $chris = $handler(new SearchFlightsQuery(new \DateTimeImmutable('2026-10-29'), Direction::NlToFi, 'chris'));
        $partner = $handler(new SearchFlightsQuery(new \DateTimeImmutable('2026-10-29'), Direction::NlToFi, 'partner'));

        self::assertSame(15650, $chris->options[0]->cost->total()->cents);
        self::assertSame(15650, $partner->options[0]->cost->total()->cents);
    }

    public function test_the_train_price_is_not_fetched_when_no_helsinki_option_survives(): void
    {
        $flights = new InMemoryFlightPriceSource();
        $flights->give(Airport::Ams, Airport::Tku, [self::throughTicket(Carrier::Sas, 150)]);

        $train = FixedTrainPriceSource::withPrice(Money::fromEuros(22.50));

        $this->handler($flights, $train)(
            new SearchFlightsQuery(new \DateTimeImmutable('2026-10-29'), Direction::NlToFi, 'chris'),
        );

        self::assertSame(0, $train->callCount, 'geen HEL-optie, dus geen apify-tegoed verbruiken');
    }

    public function test_the_train_price_is_not_fetched_when_the_only_helsinki_option_is_filtered_out(): void
    {
        $flights = new InMemoryFlightPriceSource();
        $flights->give(Airport::Ams, Airport::Hel, [
            self::viaHel(Carrier::Finnair, 118, '18:30', '22:15'), // aankomst 22:15 valt af op tijdvenster
        ]);
        $flights->give(Airport::Ams, Airport::Tku, [
            self::throughTicket(Carrier::Sas, 150),
        ]);

        $train = FixedTrainPriceSource::withPrice(Money::fromEuros(22.50));

        $result = $this->handler($flights, $train)(
            new SearchFlightsQuery(new \DateTimeImmutable('2026-10-29'), Direction::NlToFi, 'chris'),
        );

        self::assertSame(0, $train->callCount, 'de HEL-vlucht viel af op de reisregels, dus geen treinprijs nodig');
        self::assertCount(1, $result->options, 'alleen het doorgaande ticket blijft over — de nul-call komt niet door een lege uitkomst');
    }

    public function test_the_train_price_is_fetched_only_once(): void
    {
        $flights = new InMemoryFlightPriceSource();
        $flights->give(Airport::Ams, Airport::Hel, [
            self::viaHel(Carrier::Finnair, 134, '13:00', '16:45'),
            self::viaHel(Carrier::Finnair, 145, '14:00', '17:45'),
        ]);

        $train = FixedTrainPriceSource::withPrice(Money::fromEuros(22.50));

        $this->handler($flights, $train)(
            new SearchFlightsQuery(new \DateTimeImmutable('2026-10-29'), Direction::NlToFi, 'chris'),
        );

        self::assertSame(1, $train->callCount);
    }

    public function test_the_return_direction_searches_from_finland(): void
    {
        $flights = new InMemoryFlightPriceSource();
        $flights->give(Airport::Hel, Airport::Ams, []);
        $flights->give(Airport::Tku, Airport::Ams, []);

        $this->handler($flights, FixedTrainPriceSource::withPrice(Money::zero()))(
            new SearchFlightsQuery(new \DateTimeImmutable('2026-10-25'), Direction::FiToNl, 'partner'),
        );

        self::assertSame(
            [[Airport::Hel, Airport::Ams, '2026-10-25'], [Airport::Tku, Airport::Ams, '2026-10-25']],
            $flights->calls,
        );
    }

    public function test_return_flight_via_helsinki_includes_train_cost(): void
    {
        $flights = new InMemoryFlightPriceSource();
        $flights->give(Airport::Hel, Airport::Ams, [
            self::helToAms(Carrier::Finnair, 80, '14:00'),
        ]);
        $flights->give(Airport::Tku, Airport::Ams, []);

        $train = FixedTrainPriceSource::withPrice(Money::fromEuros(22.50));

        $result = $this->handler($flights, $train)(
            new SearchFlightsQuery(new \DateTimeImmutable('2026-10-25'), Direction::FiToNl, 'chris'),
        );

        self::assertCount(1, $result->options);
        self::assertSame(1, $train->callCount, 'treinprijs moet worden opgehaald voor HEL→AMS');
        self::assertSame(10250, $result->options[0]->cost->total()->cents, 'basis €80 + trein €22,50 = €102,50');
    }

    public function test_return_flight_departing_before_noon_is_rejected(): void
    {
        $flights = new InMemoryFlightPriceSource();
        $flights->give(Airport::Hel, Airport::Ams, [
            self::helToAms(Carrier::Finnair, 80, '08:00'), // te vroeg
        ]);
        $flights->give(Airport::Tku, Airport::Ams, []);

        $result = $this->handler($flights, FixedTrainPriceSource::withPrice(Money::fromEuros(22.50)))(
            new SearchFlightsQuery(new \DateTimeImmutable('2026-10-25'), Direction::FiToNl, 'chris'),
        );

        self::assertCount(0, $result->options,
            'Een HEL→AMS-vlucht met vertrek 08:00 moet worden afgewezen (vóór 12:00).');
    }

    public function test_real_adapter_hel_to_ams_finnair_gets_train_pattern_and_correct_total(): void
    {
        // Integratietest: de échte IgnavFlightSource (met MockHttpClient) draait door
        // de échte SearchFlightsHandler voor Direction::FiToNl.
        // Doel: dekt de patroonbepaling via $trainNeeded in de adapter — de twee bestaande
        // handlertests (test_return_flight_via_helsinki_includes_train_cost en
        // test_return_flight_departing_before_noon_is_rejected) gebruiken de helToAms()-helper
        // die RoutePattern::ViaHelsinkiWithTrain hardcodeert en de adapter nooit raken.
        // Mutatietest: als $trainNeeded = Airport::Hel === $destination || Airport::Hel === $origin
        // wordt teruggedraaid naar alleen-bestemming, valt alleen deze test; de twee bestaande
        // handlertests blijven groen.
        $helToAmsJson = json_encode([
            'itineraries' => [[
                'price' => ['amount' => 80, 'currency' => 'EUR', 'status' => 'verified'],
                'requires_self_transfer' => false,
                'outbound' => [
                    'segments' => [[
                        'marketing_carrier_code' => 'AY',
                        'flight_number'          => '841',
                        'departure_airport'      => 'HEL',
                        'departure_time_utc'     => '2026-10-25T14:00:00Z',
                        'arrival_airport'        => 'AMS',
                        'arrival_time_utc'       => '2026-10-25T16:45:00Z',
                    ]],
                ],
            ]],
        ], JSON_THROW_ON_ERROR);

        // De handler zoekt voor FiToNl zowel HEL→AMS als TKU→AMS op.
        // Beide calls geven hetzelfde antwoord (HEL→AMS). De routecontrole in de adapter
        // slaat de HEL→AMS-vlucht automatisch over bij de TKU→AMS-zoekopdracht,
        // zodat we exact één resultaat krijgen.
        $client = new MockHttpClient(new MockResponse($helToAmsJson));

        $source = new IgnavFlightSource($client, 'test-key');
        $handler = new SearchFlightsHandler(
            $source,
            FixedTrainPriceSource::withPrice(Money::fromEuros(22.50)),
            new TotalCostCalculator(trainCostEnabled: true),
            new NullPriceHistory(),
        );

        $result = $handler(new SearchFlightsQuery(new \DateTimeImmutable('2026-10-25'), Direction::FiToNl, 'chris'));

        self::assertCount(1, $result->options, 'Één geldige HEL→AMS Finnair-vlucht verwacht');

        self::assertSame(10250, $result->options[0]->cost->total()->cents,
            'basis €80 + trein €22,50 = €102,50; adapter moet HEL als oorsprong herkennen als ViaHelsinkiWithTrain');
    }

    public function test_unknown_train_price_option_goes_last_without_estimate(): void
    {
        // Nieuwe aanpak (bevinding 1): geen schatting, geen aanname.
        // Opties met onbekende treinprijs worden ACHTERAAN gezet, in binnenkomstvolgorde.
        // SAS doorgaand €150 (trainCostKnown=true) staat vóór Finnair via HEL €140
        // (trainCostKnown=false, onbekende trein), ook al is €140 < €150.
        $flights = new InMemoryFlightPriceSource();
        $flights->give(Airport::Ams, Airport::Hel, [
            self::viaHel(Carrier::Finnair, 140, '13:00', '16:45'),
        ]);
        $flights->give(Airport::Ams, Airport::Tku, [
            self::throughTicket(Carrier::Sas, 150),
        ]);

        $result = $this->handler($flights, FixedTrainPriceSource::withUnknownPrice())(
            new SearchFlightsQuery(new \DateTimeImmutable('2026-10-29'), Direction::NlToFi, 'chris'),
        );

        self::assertCount(2, $result->options);
        // SAS staat eerste: trainCostKnown=true, totaal €150
        self::assertSame(15000, $result->options[0]->cost->total()->cents,
            'SAS doorgaand staat eerste: trainCostKnown=true, €150');
        // Finnair staat tweede: trainCostKnown=false — geen schatting opgeteld
        self::assertSame(14000, $result->options[1]->cost->total()->cents,
            'Finnair getoond als €140 — geen schatting, staat achteraan omdat treinprijs onbekend');
        self::assertFalse($result->options[1]->cost->trainCostKnown,
            'Finnair-optie heeft trainCostKnown=false');
    }

    private function handler(InMemoryFlightPriceSource $flights, FixedTrainPriceSource $train): SearchFlightsHandler
    {
        return new SearchFlightsHandler(
            $flights,
            $train,
            new TotalCostCalculator(trainCostEnabled: true),
            new NullPriceHistory(),
        );
    }

    private static function helToAms(Carrier $carrier, float $euros, string $dep): Itinerary
    {
        return new Itinerary(
            $carrier,
            [new Segment(
                Airport::Hel,
                Airport::Ams,
                new \DateTimeImmutable('2026-10-25 '.$dep),
                new \DateTimeImmutable('2026-10-25 16:45'),
                'AY841',
            )],
            Money::fromEuros($euros),
            RoutePattern::ViaHelsinkiWithTrain,
        );
    }

    private static function viaHel(Carrier $carrier, float $euros, string $dep, string $arr): Itinerary
    {
        return new Itinerary(
            $carrier,
            [new Segment(
                Airport::Ams,
                Airport::Hel,
                new \DateTimeImmutable('2026-10-29 '.$dep),
                new \DateTimeImmutable('2026-10-29 '.$arr),
                'AY842',
            )],
            Money::fromEuros($euros),
            RoutePattern::ViaHelsinkiWithTrain,
        );
    }

    private static function throughTicket(Carrier $carrier, float $euros): Itinerary
    {
        return new Itinerary(
            $carrier,
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
            Money::fromEuros($euros),
            RoutePattern::ThroughTicket,
        );
    }
}
