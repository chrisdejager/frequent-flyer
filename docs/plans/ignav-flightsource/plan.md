# IgnavFlightSource Implementation Plan

> **STATUS: volledig uitgevoerd op 17 sep 2026.** De vinkjes hieronder zijn tijdens
> de uitvoering niet bijgewerkt — lees ze als "gepland", niet als "open". Geleverd in
> `cc3e3ab` (adapter + 33 tests), `b5c74f5` (bedrading, Bright Data verwijderd),
> `75713d9` + `7d1ec7f` (schakelaar transfer HEL–TKU, backend en UI) en `44453cc`
> (treinprijs niet meer ophalen als de schakelaar uitstaat — dat stond niet in dit
> plan, maar kwam boven toen de eigenaar de app voor het eerst met de hand draaide).

**Goal:** Vervang `BrightDataFlightSource` door `IgnavFlightSource`, die tegen de
échte ignav-API werkt, en zet de treinprijs tijdelijk uit achter één schakelaar.

**Architecture:** De poort `FlightPriceSource` blijft ongewijzigd. Alle gehárde
domeinmapping in `toItinerary()` — routecontrole, maatschappij-prioriteit,
prijsplausibiliteit, de `>2 segmenten`-grens, de `trainNeeded`-afleiding — is
bronneutraal en verhuist vrijwel letterlijk mee. Alleen het transport (endpoint,
auth, request body) en de veldnamen veranderen. De adapter wordt daarbij netto
kleiner: `Carrier::tryFrom()` vervangt de `AIRLINES`-naamtabel en
`departure_time_utc` maakt het parsen van lokale tijden zonder offset overbodig.

**Tech Stack:** PHP 8.4, Symfony 7.4 LTS, PHPUnit, `MockHttpClient`.

## Global Constraints

- **Geen Python.** PHP 8.4 / Symfony 7.4 LTS + Vue 3.
- `declare(strict_types=1)` in elk nieuw bestand.
- `src/Domain/**` blijft framework-vrij (`DomainPurityTest` bewaakt dit).
- Geldbedragen altijd in **hele centen als int**.
- Maatschappijen **AY/SK/KL**; luchthavens **AMS/HEL/TKU/ARN/CPH**; overstap **≤120 minuten**.
- **Geen enkele test mag het netwerk op.** Alles via `MockHttpClient`.
- Bewijs elke fix met een mutatie: een fix zonder test die faalt zónder die fix is geen fix.
- PHPUnit-config heet `phpunit.dist.xml`; werkpad in de container is `/app` (= `api/`).

## Vastgelegde beslissingen van de eigenaar

| Onderwerp | Besluit |
|---|---|
| `requires_self_transfer: true` | **Afwijzen.** Geen doorgaand ticket, bagage niet doorgecheckt — de bagagerekensom gaat uit van één ticket. |
| `price.status` | **Negeren.** Elke prijs accepteren ongeacht de status. |
| `BrightDataFlightSource` | **Vervangen**, inclusief zijn tests. Heeft nooit tegen de echte API gewerkt. |
| Treinprijs | **Uitzetten** achter één config-schakelaar: kosten €0, wél zichtbaar label "exclusief transfer HEL→TKU". Geen onzichtbare nul. |

## De echte API

```
POST https://ignav.com/api/fares/one-way
X-Api-Key: <key>
Content-Type: application/json

{"origin":"AMS","destination":"HEL","departure_date":"2026-10-17","market":"NL"}
```

1000 gratis requests/maand, geen rate limit, alleen geslaagde requests tellen.

**Veldmapping**

| ignav | domein | opmerking |
|---|---|---|
| `itineraries[]` | één `Itinerary` per element | was `best_flights` + `other_flights` |
| `price.amount` | `Money::fromCents((int) round($euros * 100))` | **hele euro's** (248, 266), niet centen |
| `price.currency` | moet `"EUR"` zijn | anders overslaan |
| `requires_self_transfer` | `true` → overslaan | |
| `outbound.segments[]` | `Segment[]` | was `flights[]` |
| `marketing_carrier_code` | `Carrier::tryFrom()` | `"AY"`/`"SK"`/`"KL"`; `"BT"`/`"TK"` → null → overslaan |
| `flight_number` | `$code . $number` → `"AY1302"` | ignav levert `"1302"` zónder prefix |
| `departure_airport` | `Airport::tryFrom()` | platte string, was `departure_airport.id` |
| `departure_time_utc` | `new \DateTimeImmutable(...)` | `"…Z"`, was lokale tijd zonder offset |

**Wat de eerste echte respons al bevatte** — dit zijn geen theoretische gevallen:
Air Baltic (`BT`) en Turkish (`TK`) buiten de toegestane maatschappijen, en
overstappen via `RIX` en `IST` buiten de toegestane luchthavens.

---

## File Structure

| Bestand | Verantwoordelijkheid |
|---|---|
| Create `api/src/Infrastructure/Source/IgnavFlightSource.php` | HTTP-transport + mapping ignav-JSON → `Itinerary[]` |
| Create `api/tests/Infrastructure/Source/IgnavFlightSourceTest.php` | Adaptertests, alles via `MockHttpClient` |
| Create `api/tests/Infrastructure/Source/fixtures/ignav-ams-hel.json` | De échte respons als fixture |
| Modify `api/config/services.yaml` | Bedrading omzetten naar de nieuwe bron |
| Modify `api/.env` | `BRIGHTDATA_*` eruit, `IGNAV_API_KEY` erin |
| Delete `api/src/Infrastructure/Source/BrightDataFlightSource.php` | 293 regels |
| Delete `api/tests/Infrastructure/Source/BrightDataFlightSourceTest.php` | 33 testmethodes |
| Modify `api/tests/Application/SearchFlights/SearchFlightsHandlerTest.php` | 11 testmethodes die de oude bron noemen |

---

### Task 1: IgnavFlightSource — transport en de gelukkige route

**Files:**
- Create: `api/tests/Infrastructure/Source/fixtures/ignav-ams-hel.json`
- Create: `api/tests/Infrastructure/Source/IgnavFlightSourceTest.php`
- Create: `api/src/Infrastructure/Source/IgnavFlightSource.php`

**Interfaces:**
- Consumes: `App\Domain\Port\FlightPriceSource::search(Airport $from, Airport $to, \DateTimeImmutable $date): array`
- Produces: `IgnavFlightSource::__construct(HttpClientInterface $http, string $apiKey, ?LoggerInterface $logger = null)`

- [ ] **Step 1: Sla de echte respons op als fixture**

Maak `api/tests/Infrastructure/Source/fixtures/ignav-ams-hel.json` met de
verbatim respons die de eigenaar heeft aangeleverd (AMS→HEL, 2026-10-17, acht
itineraries: Air Baltic 248, Finnair 266 ×2, KLM 273/302/386, SAS 442, Turkish 547).

Deze fixture is het ankerpunt: hij is echt gemeten, niet verzonnen. Vier van de
acht itineraries horen te worden afgewezen (BT, TK, en de twee met een overstap
via RIX/IST — dat zijn dezelfde twee).

- [ ] **Step 2: Schrijf de falende test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Source;

use App\Domain\Flight\Airport;
use App\Domain\Flight\Carrier;
use App\Domain\Flight\RoutePattern;
use App\Infrastructure\Source\IgnavFlightSource;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class IgnavFlightSourceTest extends TestCase
{
    private function sourceReturning(array $payload, ?callable $inspect = null): IgnavFlightSource
    {
        $client = new MockHttpClient(function (string $method, string $url, array $options) use ($payload, $inspect): MockResponse {
            $inspect?->__invoke($method, $url, $options);

            return new MockResponse(json_encode($payload, JSON_THROW_ON_ERROR));
        });

        return new IgnavFlightSource($client, 'test-key');
    }

    private function realPayload(): array
    {
        return json_decode(
            file_get_contents(__DIR__.'/fixtures/ignav-ams-hel.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
    }

    public function test_maps_the_real_response_to_allowed_carriers_only(): void
    {
        $source = $this->sourceReturning($this->realPayload());

        $itineraries = $source->search(Airport::Ams, Airport::Hel, new \DateTimeImmutable('2026-10-17'));

        // Acht itineraries in, vier eruit: Air Baltic (BT) en Turkish (TK) vallen af
        // op maatschappij, en dat zijn precies de twee met een overstap via RIX/IST.
        self::assertCount(6, $itineraries);

        $carriers = array_map(static fn ($i) => $i->carrier, $itineraries);
        self::assertSame(
            [Carrier::Finnair, Carrier::Finnair, Carrier::Klm, Carrier::Klm, Carrier::Klm, Carrier::Sas],
            $carriers,
        );
    }

    public function test_price_amount_is_whole_euros_not_cents(): void
    {
        $source = $this->sourceReturning($this->realPayload());

        $itineraries = $source->search(Airport::Ams, Airport::Hel, new \DateTimeImmutable('2026-10-17'));

        // Finnair staat in de fixture op amount 266 met currency EUR.
        // 266 euro is 26600 cent. Een factor-100-fout maakt hier 266 of 2660000 van.
        self::assertSame(26600, $itineraries[0]->basePrice->cents);
    }

    public function test_flight_number_gets_the_carrier_code_as_prefix(): void
    {
        $source = $this->sourceReturning($this->realPayload());

        $itineraries = $source->search(Airport::Ams, Airport::Hel, new \DateTimeImmutable('2026-10-17'));

        // ignav levert flight_number "1302" zónder maatschappijcode.
        self::assertSame('AY1302', $itineraries[0]->segments[0]->flightNumber);
    }

    public function test_times_are_read_from_the_utc_fields(): void
    {
        $source = $this->sourceReturning($this->realPayload());

        $itineraries = $source->search(Airport::Ams, Airport::Hel, new \DateTimeImmutable('2026-10-17'));

        // departure_time_local is 11:55 Amsterdam, departure_time_utc is 09:55Z.
        // Lezen we per ongeluk het lokale veld, dan staat hier 11:55.
        self::assertSame('2026-10-17T09:55:00+00:00', $itineraries[0]->segments[0]->departAt->format('c'));
        self::assertSame('2026-10-17T12:15:00+00:00', $itineraries[0]->segments[0]->arriveAt->format('c'));
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
        self::assertContains('X-Api-Key: test-key', $seen['options']['headers']);

        $body = json_decode($seen['options']['body'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(
            ['origin' => 'AMS', 'destination' => 'TKU', 'departure_date' => '2026-10-17', 'market' => 'NL'],
            $body,
        );
    }

    public function test_via_helsinki_gets_the_train_pattern(): void
    {
        $source = $this->sourceReturning($this->realPayload());

        $itineraries = $source->search(Airport::Ams, Airport::Hel, new \DateTimeImmutable('2026-10-17'));

        // Helsinki is eindpunt van dit ticket, dus de VR-trein hoort erbij.
        self::assertSame(RoutePattern::ViaHelsinkiWithTrain, $itineraries[0]->pattern);
    }
}
```

- [ ] **Step 3: Draai de test en controleer dat hij faalt**

```bash
docker compose exec -T php php bin/phpunit --filter IgnavFlightSourceTest
```

Verwacht: FAIL met `Class "App\Infrastructure\Source\IgnavFlightSource" not found`.

- [ ] **Step 4: Schrijf de implementatie**

Maak `api/src/Infrastructure/Source/IgnavFlightSource.php`:

```php
<?php

declare(strict_types=1);

namespace App\Infrastructure\Source;

use App\Domain\Flight\Airport;
use App\Domain\Flight\Carrier;
use App\Domain\Flight\Itinerary;
use App\Domain\Flight\Money;
use App\Domain\Flight\RoutePattern;
use App\Domain\Flight\Segment;
use App\Domain\Port\FlightPriceSource;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class IgnavFlightSource implements FlightPriceSource
{
    private const ENDPOINT = 'https://ignav.com/api/fares/one-way';

    /** Landcode die ignav gebruikt om de markt (en daarmee de valuta) te kiezen. */
    private const MARKET = 'NL';

    /** De enige valuta die we accepteren; het domein rekent in eurocenten. */
    private const CURRENCY = 'EUR';

    /**
     * Bovengrens voor een vliegticketprijs in euro.
     * Geen ticket op onze routes (AMS–HEL/TKU) komt in de buurt van dit bedrag;
     * een hogere waarde duidt op een bronartefact, niet op een dure vlucht.
     * Vangt tevens INF af: json_decode maakt van 1e999 een INF-float.
     */
    private const MAX_TICKET_PRICE_EUROS = 1_000_000.0;

    /**
     * Volgorde van maatschappijen van duurste naar goedkoopste bagagetoeslag.
     * Bij gemengde legs kiezen we de maatschappij die de hoogste kosten impliceert (conservatief).
     */
    private const CARRIER_PRIORITY = [
        Carrier::Klm,
        Carrier::Finnair,
        Carrier::Sas,
    ];

    public function __construct(
        private HttpClientInterface $http,
        private string $apiKey,
        private ?LoggerInterface $logger = null,
    ) {
    }

    public function search(Airport $from, Airport $to, \DateTimeImmutable $date): array
    {
        try {
            $response = $this->http->request('POST', self::ENDPOINT, [
                'headers' => [
                    'X-Api-Key: '.$this->apiKey,
                    'Content-Type: application/json',
                ],
                'json' => [
                    'origin' => $from->value,
                    'destination' => $to->value,
                    'departure_date' => $date->format('Y-m-d'),
                    'market' => self::MARKET,
                ],
            ]);

            $payload = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Symfony\Contracts\HttpClient\Exception\ExceptionInterface $e) {
            $this->logger?->warning('ignav HTTP-fout bij ophalen vluchten ({from}→{to}, {date}): {reason}', [
                'from'   => $from->value,
                'to'     => $to->value,
                'date'   => $date->format('Y-m-d'),
                'reason' => $e->getMessage(),
            ]);

            return [];
        } catch (\JsonException $e) {
            $this->logger?->warning('ignav gaf onleesbare JSON voor vluchten ({from}→{to}, {date}): {reason}', [
                'from'   => $from->value,
                'to'     => $to->value,
                'date'   => $date->format('Y-m-d'),
                'reason' => $e->getMessage(),
            ]);

            return [];
        }

        // Onderscheid "geen vluchten gevonden" (legitiem lege lijst) van "de bron
        // heeft zijn antwoordvorm gewijzigd" (sleutel ontbreekt of is geen array).
        // De oude adapter gooide die twee op één hoop en gaf stil 0 resultaten.
        if (!is_array($payload) || !is_array($payload['itineraries'] ?? null)) {
            $this->logger?->warning('Geen itineraries-collectie in payload voor route {from}→{to} ({date}).', [
                'from' => $from->value,
                'to'   => $to->value,
                'date' => $date->format('Y-m-d'),
            ]);

            return [];
        }

        $itineraries = [];
        $skipped = 0;

        foreach ($payload['itineraries'] as $offer) {
            if (!is_array($offer)) {
                ++$skipped;
                continue;
            }

            $itinerary = $this->toItinerary($offer, $from, $to);

            if (null !== $itinerary) {
                $itineraries[] = $itinerary;
            } else {
                ++$skipped;
            }
        }

        if ($skipped > 0) {
            $this->logger?->warning('{count} aanbod(en) overgeslagen voor route {from}→{to} ({date}).', [
                'count' => $skipped,
                'from'  => $from->value,
                'to'    => $to->value,
                'date'  => $date->format('Y-m-d'),
            ]);
        }

        return $itineraries;
    }

    /**
     * @param array<string, mixed> $offer
     */
    private function toItinerary(array $offer, Airport $from, Airport $to): ?Itinerary
    {
        // Een self-transfer is geen doorgaand ticket: bagage wordt niet doorgecheckt
        // en bij een gemiste aansluiting is er geen recht. De bagageberekening verderop
        // gaat uit van één ticket en zou hier stil een verkeerd totaal geven.
        if (true === ($offer['requires_self_transfer'] ?? false)) {
            return null;
        }

        $legs = $offer['outbound']['segments'] ?? null;

        if (!is_array($legs) || [] === $legs) {
            return null;
        }

        // Verzamel de maatschappijen van álle legs.
        // Carrier::tryFrom kent alleen AY/SK/KL; BT (Air Baltic) en TK (Turkish)
        // uit de echte respons vallen hier af.
        $carriers = [];
        foreach ($legs as $leg) {
            if (!is_array($leg)) {
                return null;
            }

            $code = $leg['marketing_carrier_code'] ?? null;
            $legCarrier = is_string($code) ? Carrier::tryFrom($code) : null;

            if (null === $legCarrier) {
                // Eén maatschappij buiten Finnair/SAS/KLM mag de hele zoekopdracht niet slopen.
                return null;
            }

            $carriers[] = $legCarrier;
        }

        // Bij gemengde legs kiezen we de maatschappij met de hoogste bagagetoeslag (conservatief).
        $carrier = null;
        foreach (self::CARRIER_PRIORITY as $priority) {
            if (in_array($priority, $carriers, true)) {
                $carrier = $priority;
                break;
            }
        }

        // $carrier kan hier niet null zijn: alle carriers zijn toegestaan en CARRIER_PRIORITY
        // bevat alle toegestane carriers. Maar voor type-safety:
        if (null === $carrier) {
            return null;
        }

        $segments = [];

        foreach ($legs as $leg) {
            $legFrom = Airport::tryFrom(is_string($leg['departure_airport'] ?? null) ? $leg['departure_airport'] : '');
            $legTo = Airport::tryFrom(is_string($leg['arrival_airport'] ?? null) ? $leg['arrival_airport'] : '');

            // Overstappen buiten AMS/HEL/TKU/ARN/CPH vallen hier af — de echte
            // respons bevatte RIX en IST.
            if (null === $legFrom || null === $legTo) {
                return null;
            }

            $departureTime = $leg['departure_time_utc'] ?? null;
            $arrivalTime = $leg['arrival_time_utc'] ?? null;

            // new \DateTimeImmutable('') gooit géén exception maar levert "nu" terug;
            // een lege string moet daarom expliciet vóór de constructor worden afgevangen.
            if (!is_string($departureTime) || '' === $departureTime
                || !is_string($arrivalTime) || '' === $arrivalTime) {
                return null;
            }

            try {
                $departAt = new \DateTimeImmutable($departureTime);
                $arriveAt = new \DateTimeImmutable($arrivalTime);
            } catch (\Exception) {
                return null;
            }

            // ignav levert het vluchtnummer zónder maatschappijcode ("1302"),
            // het domein verwacht "AY1302". Een niet-scalaire waarde zou bij een
            // cast stil "Array" opleveren.
            $rawFlightNumber = $leg['flight_number'] ?? null;

            if (!is_string($rawFlightNumber) && !is_int($rawFlightNumber)) {
                return null;
            }

            // Segment gooit een InvalidArgumentException als aankomst niet ná vertrek ligt;
            // ook dat hoort één aanbieding te laten vervallen, niet de hele zoekopdracht.
            try {
                $segments[] = new Segment(
                    $legFrom,
                    $legTo,
                    $departAt,
                    $arriveAt,
                    $leg['marketing_carrier_code'].str_replace(' ', '', (string) $rawFlightNumber),
                );
            } catch (\InvalidArgumentException) {
                return null;
            }
        }

        if (count($segments) > 2) {
            return null;
        }

        $origin = $segments[0]->from;
        $destination = $segments[count($segments) - 1]->to;

        // Routecontrole: het aanbod moet exact de gevraagde route bedienen.
        // Een vlucht AMS→ARN bij een AMS→TKU-zoekopdracht wordt hier overgeslagen.
        if ($origin !== $from || $destination !== $to) {
            return null;
        }

        // Helsinki is een eindpunt van de vlucht (vertrek of aankomst): de VR-trein HEL–TKU hoort erbij.
        // Let op: dit gaat over het eindpunt van dit ticket, niet over een tussenlandse overstap —
        // een ticket dat via Helsinki overstapt maar ergens anders eindigt krijgt terecht ThroughTicket.
        $trainNeeded = Airport::Hel === $destination || Airport::Hel === $origin;

        $price = $offer['price'] ?? null;

        if (!is_array($price)) {
            return null;
        }

        // Het domein rekent in eurocenten. Een andere valuta zou hier stil een
        // verkeerd bedrag opleveren.
        if (self::CURRENCY !== ($price['currency'] ?? null)) {
            return null;
        }

        // price.amount is uitgedrukt in hele euro's (gemeten: 248, 266, 273),
        // niet in centen. is_numeric() keurt strings met komma als decimaalscheider
        // af, wat de Nederlandse locale-notatie uitsluit.
        $rawAmount = $price['amount'] ?? null;

        if (!is_numeric($rawAmount)) {
            return null;
        }

        $euros = (float) $rawAmount;

        if ($euros > self::MAX_TICKET_PRICE_EUROS) {
            return null;
        }

        $cents = (int) round($euros * 100);

        // Een prijs van nul cent of minder is geen bruikbare prijs. De toets staat
        // op centniveau omdat de spookvlucht daar ontstaat: zowel 0, 0.004 als
        // -0.004 worden (int) round(...*100) = 0 cent.
        if ($cents <= 0) {
            return null;
        }

        try {
            $priceMoney = Money::fromCents($cents);
        } catch (\InvalidArgumentException) {
            return null;
        }

        return new Itinerary(
            $carrier,
            $segments,
            $priceMoney,
            $trainNeeded ? RoutePattern::ViaHelsinkiWithTrain : RoutePattern::ThroughTicket,
        );
    }
}
```

- [ ] **Step 5: Draai de test en controleer dat hij slaagt**

```bash
docker compose exec -T php php bin/phpunit --filter IgnavFlightSourceTest
```

Verwacht: `OK (6 tests, ...)`.

- [ ] **Step 6: Bewijs de factor-100-bescherming met een mutatie**

Verander in `IgnavFlightSource.php` de regel `$cents = (int) round($euros * 100);`
tijdelijk in `$cents = (int) round($euros);` en draai opnieuw.

Verwacht: `test_price_amount_is_whole_euros_not_cents` FAALT met `266 !== 26600`.
Draai de mutatie daarna terug met
`git checkout -- api/src/Infrastructure/Source/IgnavFlightSource.php` — let op dat
je het bestand dan nog niet gecommit moet hebben, of gebruik een handmatige terugdraai.

- [ ] **Step 7: Commit**

```bash
git add api/src/Infrastructure/Source/IgnavFlightSource.php \
        api/tests/Infrastructure/Source/IgnavFlightSourceTest.php \
        api/tests/Infrastructure/Source/fixtures/ignav-ams-hel.json
git commit -m "feat(adapter): IgnavFlightSource mapt de echte ignav-respons naar Itinerary"
```

---

### Task 2: De afwijsregels, elk met een eigen test

**Files:**
- Modify: `api/tests/Infrastructure/Source/IgnavFlightSourceTest.php`

**Interfaces:**
- Consumes: `IgnavFlightSource::search()` uit Task 1. Geen productiecode-wijziging
  verwacht — deze taak bewijst dat de regels uit Task 1 echt werken. Faalt een
  test, dan is de bijbehorende regel in Task 1 fout en repareer je hem hier.

- [ ] **Step 1: Schrijf de tests**

Voeg toe aan `IgnavFlightSourceTest`:

```php
    /** @param array<string, mixed> $overrides */
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

    private function searchWith(array $offer): array
    {
        return $this->sourceReturning(['itineraries' => [$offer]])
            ->search(Airport::Ams, Airport::Hel, new \DateTimeImmutable('2026-10-17'));
    }

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
        self::assertCount(0, $this->searchWith($this->offer(['price' => ['amount' => '1.234,00']])));
    }

    public function test_absurd_price_is_rejected(): void
    {
        self::assertCount(0, $this->searchWith($this->offer(['price' => ['amount' => 5_000_000]])));
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
        // Gevraagd AMS→HEL, geleverd AMS→ARN. Dit was Critical C1:
        // een vlucht naar Stockholm werd gepresenteerd als reis naar Turku.
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
```

- [ ] **Step 2: Draai de tests**

```bash
docker compose exec -T php php bin/phpunit --filter IgnavFlightSourceTest
```

Verwacht: alle tests slagen. **Faalt er één, dan is de bijbehorende regel in
Task 1 fout — repareer de productiecode, niet de test.** Een test die om het
probleem heen werkt in plaats van het te dichten is precies het patroon dat deze
codebase drie keer heeft gebeten.

- [ ] **Step 3: Commit**

```bash
git add api/tests/Infrastructure/Source/IgnavFlightSourceTest.php
git commit -m "test(adapter): dek elke afwijsregel van IgnavFlightSource af"
```

---

### Task 3: Maatschappij-prioriteit over gemengde legs, met mutatiebewijs

**Files:**
- Modify: `api/tests/Infrastructure/Source/IgnavFlightSourceTest.php`

**Interfaces:**
- Consumes: `IgnavFlightSource::search()`, `Carrier` uit Task 1.

Dit was bevinding I1. De oorspronkelijke fix was mutatie-bestendig: "neem het
laatste been" liet 136/136 groen omdat beide multi-leg tests KLM toevallig op het
laatste been hadden. Beide spiegelingen zijn hier nodig.

- [ ] **Step 1: Schrijf de tests**

```php
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

    public function test_mixed_legs_pick_klm_when_it_is_on_the_first_leg(): void
    {
        // De spiegeling die ontbrak: zonder deze test blijft de mutatie
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
```

- [ ] **Step 2: Draai de tests en controleer dat ze slagen**

```bash
docker compose exec -T php php bin/phpunit --filter IgnavFlightSourceTest
```

- [ ] **Step 3: Bewijs het met de mutatie die de vorige ronde overleefde**

Vervang in `IgnavFlightSource.php` het hele blok

```php
        $carrier = null;
        foreach (self::CARRIER_PRIORITY as $priority) {
            if (in_array($priority, $carriers, true)) {
                $carrier = $priority;
                break;
            }
        }
```

tijdelijk door

```php
        $carrier = $carriers[array_key_last($carriers)];
```

Draai de suite. Verwacht: `test_mixed_legs_pick_klm_when_it_is_on_the_first_leg`
FAALT. Slaagt alles, dan is de dekking nog steeds niet echt — stop en meld het.

Draai de mutatie daarna terug.

- [ ] **Step 4: Commit**

```bash
git add api/tests/Infrastructure/Source/IgnavFlightSourceTest.php
git commit -m "test(adapter): mutatiebewijs voor maatschappijkeuze bij gemengde legs"
```

---

### Task 4: HTTP-fouten en onleesbare antwoorden

**Files:**
- Modify: `api/tests/Infrastructure/Source/IgnavFlightSourceTest.php`

**Interfaces:**
- Consumes: `IgnavFlightSource::__construct(HttpClientInterface, string, ?LoggerInterface)`.

- [ ] **Step 1: Schrijf de tests**

```php
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
```

Voeg onderaan het testbestand toe (buiten de testklasse, in dezelfde namespace):

```php
final class CollectingLogger extends \Psr\Log\AbstractLogger
{
    /** @var list<string> */
    public array $warnings = [];

    public function log($level, \Stringable|string $message, array $context = []): void
    {
        if (\Psr\Log\LogLevel::WARNING === $level) {
            $this->warnings[] = (string) $message;
        }
    }
}
```

- [ ] **Step 2: Draai de tests**

```bash
docker compose exec -T php php bin/phpunit --filter IgnavFlightSourceTest
```

Verwacht: alle tests slagen.

- [ ] **Step 3: Commit**

```bash
git add api/tests/Infrastructure/Source/IgnavFlightSourceTest.php
git commit -m "test(adapter): HTTP-fouten en vormwijzigingen geven nul resultaten zonder crash"
```

---

### Task 5: Bedrading omzetten en Bright Data verwijderen

**Files:**
- Modify: `api/config/services.yaml`
- Modify: `api/.env`
- Delete: `api/src/Infrastructure/Source/BrightDataFlightSource.php`
- Delete: `api/tests/Infrastructure/Source/BrightDataFlightSourceTest.php`
- Modify: `api/tests/Application/SearchFlights/SearchFlightsHandlerTest.php`
- Create: `api/tests/Infrastructure/Source/FlightSourceWiringTest.php`

**Interfaces:**
- Produces: `App\Domain\Port\FlightPriceSource` is in de container een `IgnavFlightSource`.

- [ ] **Step 1: Schrijf de falende bedradingstest**

Deze toetst door de échte container, niet langs de configuratie heen. Een fout
van factor 100 in een parameter bleef eerder onzichtbaar omdat zowel de tests als
een handmatige controle de waarde zelf meegaven.

Maak `api/tests/Infrastructure/Source/FlightSourceWiringTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Source;

use App\Domain\Port\FlightPriceSource;
use App\Infrastructure\Source\IgnavFlightSource;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class FlightSourceWiringTest extends KernelTestCase
{
    public function test_the_container_wires_ignav_as_the_flight_price_source(): void
    {
        self::bootKernel();

        $source = self::getContainer()->get(FlightPriceSource::class);

        self::assertInstanceOf(IgnavFlightSource::class, $source);
    }
}
```

Let op: `FlightPriceSource` moet publiek zijn in de testcontainer. Is hij dat
niet, voeg dan in `config/services_test.yaml` toe:

```yaml
services:
    test.App\Domain\Port\FlightPriceSource:
        alias: App\Domain\Port\FlightPriceSource
        public: true
```

en haal hem in de test op via `self::getContainer()->get('test.'.FlightPriceSource::class)`.

- [ ] **Step 2: Draai de test en controleer dat hij faalt**

```bash
docker compose exec -T php php bin/phpunit --filter FlightSourceWiringTest
```

Verwacht: FAIL — de container levert nog een `BrightDataFlightSource`.

- [ ] **Step 3: Zet de bedrading om**

Vervang in `api/config/services.yaml` het blok

```yaml
App\Infrastructure\Source\BrightDataFlightSource:
    arguments:
        $apiToken: '%env(BRIGHTDATA_TOKEN)%'
        $zone: '%env(BRIGHTDATA_ZONE)%'
        $logger: '@logger'

App\Domain\Port\FlightPriceSource: '@App\Infrastructure\Source\BrightDataFlightSource'
```

door

```yaml
App\Infrastructure\Source\IgnavFlightSource:
    arguments:
        $apiKey: '%env(IGNAV_API_KEY)%'
        $logger: '@logger'

App\Domain\Port\FlightPriceSource: '@App\Infrastructure\Source\IgnavFlightSource'
```

Vervang in `api/.env` de regels

```
BRIGHTDATA_TOKEN=
BRIGHTDATA_ZONE=serp_api
```

door

```
IGNAV_API_KEY=
```

- [ ] **Step 4: Draai de test en controleer dat hij slaagt**

```bash
docker compose exec -T php php bin/phpunit --filter FlightSourceWiringTest
```

Verwacht: PASS.

- [ ] **Step 5: Verwijder de oude bron**

```bash
git rm api/src/Infrastructure/Source/BrightDataFlightSource.php \
       api/tests/Infrastructure/Source/BrightDataFlightSourceTest.php
```

Werk daarna `api/tests/Application/SearchFlights/SearchFlightsHandlerTest.php` bij:
vervang elke verwijzing naar `BrightDataFlightSource` door `IgnavFlightSource` en
zet de payload-fixtures om naar de ignav-vorm (`itineraries` / `outbound.segments`
/ `marketing_carrier_code` / `departure_time_utc` / `price.amount` + `price.currency`),
volgens de veldmapping bovenaan dit plan. Elf testmethodes.

- [ ] **Step 6: Draai de hele suite**

```bash
docker compose exec -T php php bin/phpunit
```

Verwacht: alles groen, geen enkele verwijzing meer naar Bright Data.

```bash
grep -ri brightdata api/src api/config api/.env || echo "geen treffers"
```

Verwacht: `geen treffers`.

- [ ] **Step 7: Bewijs dat geen test het netwerk raakt**

```bash
docker run --rm --network none -v "$PWD/api:/app" -w /app flights-php:latest php bin/phpunit
```

Verwacht: alles groen.

- [ ] **Step 8: Commit**

```bash
git add -A api/
git commit -m "refactor: vervang Bright Data door ignav als vluchtbron

De Bright Data-adapter heeft nooit tegen de echte API gewerkt: hij haalde de
HTML-pagina van Google Flights op via Web Unlocker, maar parste best_flights /
other_flights — het JSON-schema van SerpApi. Die sleutels komen daar nooit uit.
De 33 tests voerden dat verzonnen schema in en zagen het gat dus niet."
```

---

### Task 6: Treinprijs uitzetten achter één schakelaar

**Files:**
- Modify: `api/src/Domain/Pricing/TotalCost.php:16` (veld erbij)
- Modify: `api/src/Domain/Pricing/TotalCostCalculator.php:20-50`
- Modify: `api/src/Infrastructure/Http/SearchResultPresenter.php:56`
- Modify: `api/config/services.yaml`
- Modify: `api/.env`
- Modify: `web/src/types.ts`, `web/src/components/OptionCard.vue:28-34`
- Test: `api/tests/Domain/Pricing/TotalCostCalculatorTest.php`,
  `api/tests/Infrastructure/Http/SearchControllerTest.php`,
  `web/src/components/__tests__/OptionCard.spec.ts`

**Interfaces:**
- Consumes: `TotalCostCalculator::calculate(Itinerary $itinerary, Traveller $traveller, ?Money $trainCost): TotalCost`
- Produces: `TotalCostCalculator::__construct(..., private bool $trainCostEnabled)`
  en een nieuw veld `TotalCost::$trainCostIncluded` (bool), dat als
  `trainCostIncluded` in de JSON belandt.

**Besluit van de eigenaar:** treinkosten €0, alle opties doen weer mee aan de
sortering, maar elke via-Helsinki-optie krijgt zichtbaar **"exclusief transfer
HEL→TKU"**. Eén schakelaar, later terug te draaien.

**Waarom er een nieuw veld nodig is en `trainCostKnown` niet volstaat:**
`trainCostKnown = false` duwt de optie naar de ongerangschikte bak — precies wat
de eigenaar níet wil. En `trainCost = 0` zonder meer is de onzichtbare nul die
spec §5 verbiedt: de frontend kan "echt gratis" dan niet onderscheiden van
"niet meegerekend". De twee begrippen zijn echt verschillend en hebben dus twee
velden nodig.

- [ ] **Step 1: Lees eerst de bestaande berekening**

```bash
docker compose exec -T php sed -n '20,50p' src/Domain/Pricing/TotalCostCalculator.php
```

De relevante bestaande regels: 28 `$trainCostKnown = true;` (geen HEL-route),
31 `$train = $trainCost ?? Money::zero();`, 32 `$trainCostKnown = null !== $trainCost;`,
39 `|| ($isHelRoute && !$trainCostKnown);`, 44 `trainCost: $train,`,
46 `trainCostKnown: $trainCostKnown,`.

- [ ] **Step 2: Schrijf de falende test**

Voeg toe aan `api/tests/Domain/Pricing/TotalCostCalculatorTest.php`:

```php
    public function test_with_the_train_disabled_a_hel_route_costs_zero_train_but_stays_ranked(): void
    {
        $calculator = $this->calculatorWithTrainEnabled(false);

        $cost = $calculator->calculate($this->helItinerary(), $this->chris(), Money::fromCents(2250));

        // De treinprijs telt niet mee in het totaal ...
        self::assertTrue($cost->trainCost->isZero());
        // ... maar de optie blijft gerangschikt: trainCostKnown mag niet false worden,
        // anders belandt elke Helsinki-optie in de ongerangschikte bak.
        self::assertTrue($cost->trainCostKnown);
        // ... en de UI moet kunnen zien dat er iets ontbreekt.
        self::assertFalse($cost->trainCostIncluded);
    }

    public function test_with_the_train_disabled_a_through_ticket_is_not_marked_as_incomplete(): void
    {
        $calculator = $this->calculatorWithTrainEnabled(false);

        $cost = $calculator->calculate($this->throughItinerary(), $this->chris(), null);

        // Een doorgaand ticket heeft geen treinbeen, dus er ontbreekt niets.
        self::assertTrue($cost->trainCostIncluded);
    }

    public function test_with_the_train_enabled_the_price_is_still_added(): void
    {
        $calculator = $this->calculatorWithTrainEnabled(true);

        $cost = $calculator->calculate($this->helItinerary(), $this->chris(), Money::fromCents(2250));

        self::assertSame(2250, $cost->trainCost->cents);
        self::assertTrue($cost->trainCostIncluded);
    }
```

Hergebruik de bestaande helpers uit dat testbestand voor `helItinerary()`,
`throughItinerary()` en `chris()`; bestaan ze onder een andere naam, gebruik dan
die. Voeg de fabrieksmethode toe:

```php
    private function calculatorWithTrainEnabled(bool $enabled): TotalCostCalculator
    {
        // Neem dezelfde constructorargumenten over als de bestaande tests gebruiken
        // en zet trainCostEnabled als laatste argument.
        return new TotalCostCalculator(/* bestaande argumenten */ trainCostEnabled: $enabled);
    }
```

- [ ] **Step 3: Draai de test en controleer dat hij faalt**

```bash
docker compose exec -T php php bin/phpunit --filter TotalCostCalculatorTest
```

Verwacht: FAIL — onbekend argument `trainCostEnabled` en onbekend veld `trainCostIncluded`.

- [ ] **Step 4: Voeg het veld toe aan TotalCost**

In `api/src/Domain/Pricing/TotalCost.php`, naast `public bool $trainCostKnown,` (regel 16):

```php
        public bool $trainCostIncluded,
```

- [ ] **Step 5: Pas de calculator aan**

Voeg `private bool $trainCostEnabled` toe aan de constructor van
`TotalCostCalculator`. Vervang de HEL-tak (rond regel 31-32) zodat de logica wordt:

```php
        if (!$isHelRoute) {
            // Doorgaand ticket: geen treinbeen, dus er ontbreekt niets.
            $train = Money::zero();
            $trainCostKnown = true;
            $trainCostIncluded = true;
        } elseif (!$this->trainCostEnabled) {
            // Trein tijdelijk uitgezet. De optie blijft meedoen aan de sortering
            // (trainCostKnown blijft true), maar het totaal is aantoonbaar
            // onvolledig — de UI moet dat tonen.
            $train = Money::zero();
            $trainCostKnown = true;
            $trainCostIncluded = false;
        } else {
            $train = $trainCost ?? Money::zero();
            $trainCostKnown = null !== $trainCost;
            $trainCostIncluded = true;
        }
```

Geef `trainCostIncluded: $trainCostIncluded,` mee aan de `TotalCost`-constructor,
naast `trainCostKnown: $trainCostKnown,` (regel 46).

- [ ] **Step 6: Draai de test en controleer dat hij slaagt**

```bash
docker compose exec -T php php bin/phpunit --filter TotalCostCalculatorTest
```

- [ ] **Step 7: Bedrading en presenter**

In `api/src/Infrastructure/Http/SearchResultPresenter.php`, naast regel 56:

```php
            'trainCostIncluded' => $cost->trainCostIncluded,
```

In `api/config/services.yaml`:

```yaml
App\Domain\Pricing\TotalCostCalculator:
    arguments:
        $trainCostEnabled: '%env(bool:TRAIN_COST_ENABLED)%'
```

In `api/.env`:

```
# Zet op 1 zodra er een transferprijs Helsinki–Turku is. Staat uit: het
# getoonde totaal is dan exclusief de transfer en de UI meldt dat.
TRAIN_COST_ENABLED=0
```

- [ ] **Step 8: Toets de schakelaar door de échte container**

Voeg toe aan `api/tests/Infrastructure/Http/SearchControllerTest.php`:

```php
    public function test_hel_options_report_the_train_as_not_included_when_the_switch_is_off(): void
    {
        // Door de echte container, niet met een zelf meegegeven waarde: een fout
        // in de parameter bleef eerder onzichtbaar omdat de tests de waarde zelf invulden.
        $client = static::createClient();
        $client->request('GET', '/api/search?from=AMS&to=HEL&date=2026-10-17&traveller=chris');

        $payload = json_decode($client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        foreach ($payload['options'] as $option) {
            if ('via_hel_train' === $option['pattern']) {
                self::assertFalse($option['cost']['trainCostIncluded']);
                self::assertSame(0, $option['cost']['trainCost']);
                self::assertTrue($option['cost']['trainCostKnown']);
            }
        }
    }
```

- [ ] **Step 9: Toon het in de UI**

In `web/src/types.ts`: voeg `trainCostIncluded: boolean` toe aan het `cost`-type.

In `web/src/components/OptionCard.vue`, vervang regels 28-34:

```ts
if (props.option.pattern === 'via_hel_train') {
  r.push({
    label: 'Trein HEL–TKU',
    cents: props.option.cost.trainCost,
    unknown: !props.option.cost.trainCostKnown,
    excluded: !props.option.cost.trainCostIncluded,
  })
}
```

En de toonlogica (regels 57-61):

```vue
<div v-for="row in rows" :key="row.label" data-testid="cost-row">
  <dt>{{ row.label }}</dt>
  <dd v-if="row.excluded" data-testid="train-excluded">niet meegerekend</dd>
  <dd v-else-if="row.unknown">onbekend</dd>
  <dd v-else>{{ euro(row.cents) }}</dd>
</div>
```

Voeg onder het totaal toe:

```vue
<p v-if="!option.cost.trainCostIncluded" class="excluded-notice">
  Totaal is exclusief transfer HEL→TKU
</p>
```

- [ ] **Step 10: Frontendtest**

Voeg toe aan `web/src/components/__tests__/OptionCard.spec.ts`:

```ts
it('meldt dat de transfer niet is meegerekend als trainCostIncluded false is', () => {
  const wrapper = mount(OptionCard, {
    props: {
      option: {
        ...viaHelOption,
        cost: { ...viaHelOption.cost, trainCost: 0, trainCostKnown: true, trainCostIncluded: false },
      },
    },
  })

  expect(wrapper.find('[data-testid="train-excluded"]').text()).toBe('niet meegerekend')
  expect(wrapper.text()).toContain('exclusief transfer HEL→TKU')
})
```

Hergebruik het bestaande via-Helsinki-fixture uit dat bestand; heet het anders,
gebruik dan die naam.

- [ ] **Step 11: Draai alles**

```bash
docker compose exec -T php php bin/phpunit
docker compose run --rm node npm run test
docker compose run --rm node npm run build
```

Verwacht: alles groen. De bestaande sorteertests blijven werken — de
"niet gerangschikt"-groep raakt met de schakelaar uit alleen leeg als er geen
enkele optie een onbekende treinprijs heeft, en dat blijft afhangen van
`trainCostKnown`, dat we bewust niet hebben aangeraakt.

- [ ] **Step 12: Commit**

```bash
git add -A api/ web/
git commit -m "feat: schakelaar om de transferprijs HEL-TKU tijdelijk uit te zetten

Met TRAIN_COST_ENABLED=0 telt de trein niet mee in het totaal, maar blijft de
optie gerangschikt. Een nieuw veld trainCostIncluded houdt 'echt gratis' en
'niet meegerekend' uit elkaar, zodat de UI het ontbrekende deel kan melden in
plaats van een onvolledig totaal als compleet te tonen."
```

---

## Wat dit plan bewust NIET doet

- **I7 (auth op `/api/search`).** Met 1000 gratis requests per maand is de
  credit-drain veel minder dringend dan bij Bright Data. Blijft open, blokkeert
  nog steeds de eerste publieke deploy.
- **I6 (`FareUpgradeRates::for()` geeft stil €0,00).** Ongewijzigd.
- **M1, M3–M10.** M2 (`flight_number` als array) wordt in Task 2 wél gedicht.
  M3 (`CARRIER_PRIORITY` codeert tariefkennis in infrastructuur) verhuist
  ongewijzigd mee en blijft open.
- **De retourrichting en de round-trip-endpoints van ignav.** De poort blijft
  `search(from, to, date)`; heen en terug zijn twee losse aanroepen, zoals nu.
