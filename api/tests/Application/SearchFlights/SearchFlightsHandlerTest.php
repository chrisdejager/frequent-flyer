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
use App\Domain\Pricing\BaggagePolicy;
use App\Domain\Pricing\FareUpgradeRates;
use App\Domain\Pricing\TotalCostCalculator;
use App\Infrastructure\Persistence\NullPriceHistory;
use App\Infrastructure\Source\BrightDataFlightSource;
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

    public function test_the_partner_gets_a_higher_total_on_finnair(): void
    {
        $flights = new InMemoryFlightPriceSource();
        $flights->give(Airport::Ams, Airport::Hel, [self::viaHel(Carrier::Finnair, 134, '13:00', '16:45')]);

        $handler = $this->handler($flights, FixedTrainPriceSource::withPrice(Money::fromEuros(22.50)));

        $chris = $handler(new SearchFlightsQuery(new \DateTimeImmutable('2026-10-29'), Direction::NlToFi, 'chris'));
        $partner = $handler(new SearchFlightsQuery(new \DateTimeImmutable('2026-10-29'), Direction::NlToFi, 'partner'));

        self::assertSame(15650, $chris->options[0]->cost->total()->cents);
        self::assertSame(19150, $partner->options[0]->cost->total()->cents);
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
        // basis € 80,00 + bagage € 0 (chris/Finnair silver = gratis) + trein € 22,50 = € 102,50
        // Baggagebeleid: Finnair Silver geeft gratis bagage, dus bagage = €0
        // Trein €22,50 = 2250 cent
        // Basis €80 = 8000 cent
        // Totaal = 8000 + 2250 = 10250
        self::assertSame(10250, $result->options[0]->cost->total()->cents,
            'basis €80 + trein €22,50 = €102,50 (Finnair Silver: gratis bagage)');
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
        // Integratietest: de échte BrightDataFlightSource (met MockHttpClient) draait door
        // de échte SearchFlightsHandler voor Direction::FiToNl.
        // Doel: dekt de patroonbepaling via $trainNeeded in de adapter — de twee bestaande
        // handlertests (test_return_flight_via_helsinki_includes_train_cost en
        // test_return_flight_departing_before_noon_is_rejected) gebruiken de helToAms()-helper
        // die RoutePattern::ViaHelsinkiWithTrain hardcodeert en de adapter nooit raken.
        // Mutatietest: als $trainNeeded = Airport::Hel === $destination || Airport::Hel === $origin
        // wordt teruggedraaid naar alleen-bestemming, valt alleen deze test; de twee bestaande
        // handlertests blijven groen.
        $helToAmsJson = json_encode([
            'best_flights' => [[
                'flights' => [[
                    'departure_airport' => ['id' => 'HEL', 'time' => '2026-10-25 14:00'],
                    'arrival_airport'   => ['id' => 'AMS', 'time' => '2026-10-25 16:45'],
                    'airline'           => 'Finnair',
                    'flight_number'     => 'AY 841',
                ]],
                'price' => 80,
            ]],
        ], JSON_THROW_ON_ERROR);

        // De handler zoekt voor FiToNl zowel HEL→AMS als TKU→AMS op.
        // We laten de MockHttpClient voor beide calls hetzelfde antwoord geven,
        // maar TKU→AMS bevat geen geldige vluchten (HEL/AMS/Finnair is de enige sleutel).
        // Omdat de JSON dezelfde vlucht bevat (HEL→AMS) voor beide calls, maar Tku-routes
        // Airport::tryFrom('HEL') and Airport::tryFrom('AMS') opleveren (dus geldig),
        // zouden we de vlucht dubbel krijgen. Geef voor TKU→AMS een leeg antwoord.
        $callIndex = 0;
        $client = new MockHttpClient(function () use ($helToAmsJson, &$callIndex): MockResponse {
            return new MockResponse(0 === $callIndex++ ? $helToAmsJson : '{"best_flights":[]}');
        });

        $source = new BrightDataFlightSource($client, 'test-token', 'serp_zone');
        $rates = FareUpgradeRates::fromConfig(['AY' => 35, 'KL' => 40]);
        $handler = new SearchFlightsHandler(
            $source,
            FixedTrainPriceSource::withPrice(Money::fromEuros(22.50)),
            new TotalCostCalculator(new BaggagePolicy($rates), $rates),
            new NullPriceHistory(),
        );

        $result = $handler(new SearchFlightsQuery(new \DateTimeImmutable('2026-10-25'), Direction::FiToNl, 'chris'));

        self::assertCount(1, $result->options, 'Één geldige HEL→AMS Finnair-vlucht verwacht');

        // basis €80 + bagage €0 (chris/Finnair Silver = gratis handbagage) + trein €22,50 = €102,50
        self::assertSame(10250, $result->options[0]->cost->total()->cents,
            'basis €80 + trein €22,50 = €102,50 (Finnair Silver: gratis bagage); adapter moet HEL als oorsprong herkennen als ViaHelsinkiWithTrain');
    }

    private function handler(InMemoryFlightPriceSource $flights, FixedTrainPriceSource $train): SearchFlightsHandler
    {
        $rates = FareUpgradeRates::fromConfig(['AY' => 35, 'KL' => 40]);

        return new SearchFlightsHandler(
            $flights,
            $train,
            new TotalCostCalculator(new BaggagePolicy($rates), $rates),
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
