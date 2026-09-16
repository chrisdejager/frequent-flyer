# Eindreview-fixes implementatieplan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Vier bevindingen uit de eindreview repareren: patroonbepaling terugreis, onleesbare prijs, ontbrekende logging en verkeerde HTTP-statuscode.

**Architecture:** Vier onafhankelijke reparaties in bestaande klassen; elke reparatie vergezeld van tests die het gat bewijzen en aantonen dat de fix het dicht. Bevinding 4 introduceert één nieuw exception-type in de domain-laag (`UnknownTraveller`).

**Tech Stack:** Symfony 7.4, PHP 8.4, PHPUnit 11, MockHttpClient (Symfony), PSR-3 logger interface, Docker (`docker compose exec -T php …`).

## Global Constraints

- `declare(strict_types=1);` in elk nieuw of gewijzigd PHP-bestand.
- `src/Domain/**` framework-vrij — geen Symfony\, Doctrine\, Psr\, Twig\-imports (architectuurtest `DomainPurityTest` controleert dit).
- `src/Infrastructure/**` mag Symfony en PSR gebruiken.
- Geldbedragen altijd in hele centen als int.
- Geen netwerktoegang in tests — gebruik `MockHttpClient`.
- Testcommando (vanuit projectroot): `docker compose exec -T php php bin/phpunit`
- Alle 80 bestaande tests moeten na elke taak groen blijven; nieuwe tests komen erbij.
- Commits per bevinding, met bericht dat uitlegt wát er mis was en waaróm het erg was.
- Rapporteer bevindingen naar `/Storage/Workspace/Projecten/flights/.superpowers/sdd/eindreview-fixes-report.md`.

---

### Task 1: Bevinding 1 — patroonbepaling klopt niet op de terugreis

**Files:**
- Modify: `api/src/Infrastructure/Source/BrightDataFlightSource.php:135-142`
- Modify: `api/tests/Infrastructure/Source/BrightDataFlightSourceTest.php` (nieuwe tests toevoegen)
- Modify: `api/tests/Application/SearchFlights/SearchFlightsHandlerTest.php` (nieuwe tests toevoegen)

**Interfaces:**
- Consumes: `BrightDataFlightSource::toItinerary()` — privémethode die een ruwe offer omzet naar `Itinerary`
- Produces: correcte `RoutePattern`-toewijzing bij zowel AMS→HEL als HEL→AMS

**Wat er mis was:** het patroon werd alleen op de bestemming van de vlucht bepaald. Bij de terugreis (HEL→AMS) is de bestemming AMS, waardoor elke retourvlucht via Helsinki het patroon `ThroughTicket` krijgt. De treinprijs wordt dan niet opgeteld en de tijdvensterregel (geen vertrek vóór 12:00) slaat nooit aan. Dat is structureel 19% te goedkoop en mist een veiligheidscontrole.

- [ ] **Stap 1: schrijf de falende test voor de adapter (HEL→AMS krijgt ViaHelsinkiWithTrain)**

Voeg onderstaande test toe aan `api/tests/Infrastructure/Source/BrightDataFlightSourceTest.php`, ná de bestaande methode `test_a_journey_ending_in_helsinki_gets_the_train_pattern`:

```php
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
```

- [ ] **Stap 2: draai de test om te bevestigen dat hij faalt**

```
docker compose exec -T php php bin/phpunit tests/Infrastructure/Source/BrightDataFlightSourceTest.php --filter test_a_journey_starting_in_helsinki_also_gets_the_train_pattern
```

Verwacht: FAIL — `RoutePattern::ThroughTicket` i.p.v. `RoutePattern::ViaHelsinkiWithTrain`.

- [ ] **Stap 3: schrijf de falende handler-test (treinprijs wordt opgeteld bij terugreis)**

Voeg onderstaande twee tests toe aan `api/tests/Application/SearchFlights/SearchFlightsHandlerTest.php`. Voeg ook een hulpmethode `helToAms()` toe naast de bestaande `viaHel()`.

Hulpmethode (toevoegen naast `viaHel`):

```php
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
```

Test 1 — treinprijs wordt opgeteld:

```php
public function test_return_flight_via_helsinki_includes_train_cost(): void
{
    $flights = new InMemoryFlightPriceSource();
    $flights->give(Airport::Hel, Airport::Ams, [
        self::helToAms(Carrier::Finnair, 80, '14:00'),
    ]);
    $flights->give(Airport::Tku, Airport::Ams, []);

    $train = new FixedTrainPriceSource(Money::fromEuros(22.50));

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
```

Test 2 — vertrek vóór 12:00 wordt afgewezen:

```php
public function test_return_flight_departing_before_noon_is_rejected(): void
{
    $flights = new InMemoryFlightPriceSource();
    $flights->give(Airport::Hel, Airport::Ams, [
        self::helToAms(Carrier::Finnair, 80, '08:00'), // te vroeg
    ]);
    $flights->give(Airport::Tku, Airport::Ams, []);

    $result = $this->handler($flights, new FixedTrainPriceSource(Money::fromEuros(22.50)))(
        new SearchFlightsQuery(new \DateTimeImmutable('2026-10-25'), Direction::FiToNl, 'chris'),
    );

    self::assertCount(0, $result->options,
        'Een HEL→AMS-vlucht met vertrek 08:00 moet worden afgewezen (vóór 12:00).');
}
```

**Let op:** de handler-tests gebruiken `Itinerary`-objecten die je zelf aanmaakt met `RoutePattern::ViaHelsinkiWithTrain`. De handler-tests bewijzen het rekengedrag van de handler; de adapter-test bewijst dat de adapter het juiste patroon toewijst. Beide zijn nodig.

**Let op over de bagageberekening:** check eerst hoe `TotalCostCalculator` bagage berekent voor Finnair Silver voordat je de totaalprijs in de test zet. Lees `api/src/Domain/Pricing/TotalCostCalculator.php` en `api/src/Domain/Pricing/BaggagePolicy.php` om de exacte cents te bepalen. Pas de verwachte waarden aan als ze afwijken.

- [ ] **Stap 4: draai de twee handler-tests om te bevestigen dat ze falen**

```
docker compose exec -T php php bin/phpunit tests/Application/SearchFlights/SearchFlightsHandlerTest.php --filter "test_return_flight_via_helsinki_includes_train_cost|test_return_flight_departing_before_noon_is_rejected"
```

Verwacht: beide FAIL of ERROR (patroon is nog niet gecorrigeerd in adapter, maar handler tests gebruiken al `ViaHelsinkiWithTrain`; de train-test kan groen zijn vanwege mock, de departure-test moet groen zijn — als ze al groen zijn, check dan of de helper methode correct is).

**Correctie:** de handler-tests bouwen `Itinerary`-objecten direct met `RoutePattern::ViaHelsinkiWithTrain`, dus ze testen de handlelogica onafhankelijk van de adapter. De adapter-test (`test_a_journey_starting_in_helsinki_also_gets_the_train_pattern`) moet falen. Draai enkel die:

```
docker compose exec -T php php bin/phpunit tests/Infrastructure/Source/BrightDataFlightSourceTest.php --filter test_a_journey_starting_in_helsinki_also_gets_the_train_pattern
```

Verwacht: FAIL.

- [ ] **Stap 5: pas de patroonbepaling aan in de adapter**

Vervang in `api/src/Infrastructure/Source/BrightDataFlightSource.php` de regels:

```php
        $destination = $segments[count($segments) - 1]->to;

        return new Itinerary(
            $carrier,
            $segments,
            Money::fromEuros((float) $offer['price']),
            Airport::Hel === $destination ? RoutePattern::ViaHelsinkiWithTrain : RoutePattern::ThroughTicket,
        );
```

Door:

```php
        $origin      = $segments[0]->from;
        $destination = $segments[count($segments) - 1]->to;

        // Helsinki als eindpunt (heen of terug) betekent dat de VR-trein HEL–TKU erbij hoort.
        $viaHelsinki = Airport::Hel === $destination || Airport::Hel === $origin;

        return new Itinerary(
            $carrier,
            $segments,
            Money::fromEuros((float) $offer['price']),
            $viaHelsinki ? RoutePattern::ViaHelsinkiWithTrain : RoutePattern::ThroughTicket,
        );
```

- [ ] **Stap 6: draai alle tests**

```
docker compose exec -T php php bin/phpunit
```

Verwacht: alle 80 + 3 nieuwe = 83 tests groen.

- [ ] **Stap 7: mutatietest — bewijs dat de nieuwe tests scherp zijn**

Zet tijdelijk de fix terug naar alleen `$destination` (de verkeerde code):

```php
        // TIJDELIJK VOOR MUTATIETEST — daarna terugzetten
        Airport::Hel === $destination ? RoutePattern::ViaHelsinkiWithTrain : RoutePattern::ThroughTicket,
```

Draai:

```
docker compose exec -T php php bin/phpunit tests/Infrastructure/Source/BrightDataFlightSourceTest.php --filter test_a_journey_starting_in_helsinki_also_gets_the_train_pattern
```

Verwacht: FAIL (de test pakt de mutatie).

Zet daarna de fix terug (de versie met `$viaHelsinki`) en draai alle tests om te bevestigen dat ze groen zijn.

- [ ] **Stap 8: commit**

```bash
git add api/src/Infrastructure/Source/BrightDataFlightSource.php \
        api/tests/Infrastructure/Source/BrightDataFlightSourceTest.php \
        api/tests/Application/SearchFlights/SearchFlightsHandlerTest.php

git commit -m "$(cat <<'EOF'
fix(adapter): patroonbepaling corrigeren voor de terugreis

Bij de terugreis (HEL→AMS) werd het patroon alleen op de bestemming bepaald.
Bestemming is dan AMS, dus elke retourvlucht via Helsinki kreeg ThroughTicket.
Gevolg: treinprijs niet opgeteld (structureel 19% te goedkoop), en de
vertrekregel (niet vóór 12:00 uit Helsinki) sloeg nooit aan.

Fix: patroon is ViaHelsinkiWithTrain als Helsinki het beginpunt óf het
eindpunt van de vlucht is. Nieuwe tests dekken beide gevallen.
EOF
)"
```

---

### Task 2: Bevinding 2 — onleesbare prijs wordt een nul of veroorzaakt crash

**Files:**
- Modify: `api/src/Infrastructure/Source/BrightDataFlightSource.php` (prijsvalidatie toevoegen)
- Modify: `api/tests/Infrastructure/Source/BrightDataFlightSourceTest.php` (nieuwe tests toevoegen)

**Interfaces:**
- Consumes: `BrightDataFlightSource::toItinerary()` — privémethode
- Produces: aanbod met niet-numerieke of negatieve prijs wordt overgeslagen (return null), net als een onleesbaar tijdstip

**Wat er mis was:** `Money::fromEuros((float) $offer['price'])` converteert `'N/A'` stilzwijgend naar €0,00 en `'1.234,00'` (Nederlandse notatie) naar €1,23 — een vlucht van €1234 lijkt dan spotgoedkoop. Een negatief getal laat `Money` een `InvalidArgumentException` gooien buiten de bestaande try/catch, waardoor de hele zoekopdracht plat gaat.

- [ ] **Stap 1: schrijf falende tests voor de prijsvalidatie**

Voeg onderstaande drie tests toe aan `api/tests/Infrastructure/Source/BrightDataFlightSourceTest.php`:

```php
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
            // Rot aanbod: negatieve prijs
            [
                'flights' => [[
                    'departure_airport' => ['id' => 'AMS', 'time' => '2026-10-29 13:00'],
                    'arrival_airport'   => ['id' => 'HEL', 'time' => '2026-10-29 16:45'],
                    'airline'           => 'Finnair',
                    'flight_number'     => 'AY 843',
                ]],
                'price' => -5,
            ],
        ],
    ], JSON_THROW_ON_ERROR));

    $found = $source->search(Airport::Ams, Airport::Hel, new \DateTimeImmutable('2026-10-29'));

    self::assertCount(1, $found, 'Negatieve prijs wordt overgeslagen zonder de hele zoekopdracht te laten crashen');
    self::assertSame('AY842', $found[0]->segments[0]->flightNumber);
}
```

- [ ] **Stap 2: draai de drie tests om te bevestigen dat ze falen**

```
docker compose exec -T php php bin/phpunit tests/Infrastructure/Source/BrightDataFlightSourceTest.php --filter "test_an_offer_with_a_non_numeric_price_is_skipped|test_an_offer_with_a_locale_formatted_price_is_skipped|test_an_offer_with_a_negative_price_is_skipped_without_crashing_the_search"
```

Verwacht: minstens 2 FAIL (N/A geeft 0 i.p.v. overgeslagen; negatief crasht).

- [ ] **Stap 3: voeg een prijsvalidatie-guard toe in `toItinerary()`**

In `api/src/Infrastructure/Source/BrightDataFlightSource.php`, vervang de regel:

```php
        return new Itinerary(
            $carrier,
            $segments,
            Money::fromEuros((float) $offer['price']),
            $viaHelsinki ? RoutePattern::ViaHelsinkiWithTrain : RoutePattern::ThroughTicket,
        );
```

Door (de guard staat vóór de Itinerary-constructie, na de segmentenopbouw):

```php
        // Een onleesbare prijs (leeg, "N/A", locale-notatie "1.234,00") of een negatieve
        // waarde mag de hele zoekopdracht niet slopen; sla dit aanbod stil over.
        // is_numeric() keurt strings met komma als decimaalscheider af, wat precies
        // de Nederlandse locale-notatie uitsluit.
        $rawPrice = $offer['price'];
        if (!is_numeric($rawPrice) || (float) $rawPrice < 0) {
            return null;
        }

        try {
            $price = Money::fromEuros((float) $rawPrice);
        } catch (\InvalidArgumentException) {
            return null;
        }

        return new Itinerary(
            $carrier,
            $segments,
            $price,
            $viaHelsinki ? RoutePattern::ViaHelsinkiWithTrain : RoutePattern::ThroughTicket,
        );
```

**Toelichting op `is_numeric()`:** PHP's `is_numeric()` accepteert `'134'`, `134`, `134.50` maar verwerpt `'1.234,00'`, `'N/A'` en `''`. Dit sluit exact de gevaarlijke gevallen uit. Een negatief getal (`-5`) passeert `is_numeric()` maar wordt gevangen door de `< 0`-check; `Money::fromEuros` gooit dan een `InvalidArgumentException` die we nu ook opvangen.

- [ ] **Stap 4: draai alle tests**

```
docker compose exec -T php php bin/phpunit
```

Verwacht: 83 + 3 nieuwe = 86 tests groen.

- [ ] **Stap 5: mutatietest — bewijs dat de tests scherp zijn**

Verwijder tijdelijk de guard (zet de oorspronkelijke `Money::fromEuros((float) $offer['price'])` terug):

```
docker compose exec -T php php bin/phpunit tests/Infrastructure/Source/BrightDataFlightSourceTest.php --filter "test_an_offer_with_a_non_numeric_price_is_skipped|test_an_offer_with_a_locale_formatted_price_is_skipped|test_an_offer_with_a_negative_price_is_skipped_without_crashing_the_search"
```

Verwacht: minstens 2 FAIL.

Zet de guard terug en draai alle tests opnieuw (86 groen).

- [ ] **Stap 6: commit**

```bash
git add api/src/Infrastructure/Source/BrightDataFlightSource.php \
        api/tests/Infrastructure/Source/BrightDataFlightSourceTest.php

git commit -m "$(cat <<'EOF'
fix(adapter): onleesbare of negatieve prijs wordt aanbod overgeslagen

'N/A' werd € 0,00; '1.234,00' (Nederlandse locale, zoals de zoekopdracht
zelf vraagt) werd € 1,23 — een vlucht van € 1234 bovenaan. Een negatief
getal liet Money een InvalidArgumentException gooien buiten de try/catch,
waardoor de hele zoekopdracht plat ging.

Fix: is_numeric()-guard slaat het aanbod over bij niet-numerieke waarden
en bij negatieve prijzen, net als de bestaande tijdstip-guard.
EOF
)"
```

---

### Task 3: Bevinding 3 — overgeslagen aanbiedingen laten geen spoor na

**Files:**
- Modify: `api/src/Infrastructure/Source/BrightDataFlightSource.php` (PSR-3 logger toevoegen)
- Modify: `api/config/services.yaml` (logger autowiren)
- Modify: `api/tests/Infrastructure/Source/BrightDataFlightSourceTest.php` (logger-test toevoegen)

**Interfaces:**
- Consumes: `Psr\Log\LoggerInterface` (nullable, standaard null)
- Produces: één `warning`-logmelding als meer dan nul aanbiedingen zijn overgeslagen

**Wat er mis was:** rotte aanbiedingen worden stil verwijderd. Als Bright Data van antwoordvorm verandert, krijg je nul resultaten zonder enige aanwijzing waarom. Met bevinding 2 worden er mogelijk meer aanbiedingen overgeslagen.

**Domeinpuurheid:** `BrightDataFlightSource` zit in `src/Infrastructure/`, dus PSR-3 is toegestaan. De architectuurtest verbiedt PSR in `src/Domain/`.

- [ ] **Stap 1: schrijf de falende logger-test**

Voeg onderstaande test toe aan `api/tests/Infrastructure/Source/BrightDataFlightSourceTest.php`. Je hebt een PSR-3 logger-stub nodig; gebruik `Psr\Log\NullLogger` voor de bestaande tests (geen extra afhankelijkheden) en een spy-logger voor de nieuwe test.

Voeg bovenaan het testbestand de import toe:
```php
use Psr\Log\AbstractLogger;
```

Voeg de test toe:

```php
public function test_skipped_offers_are_logged_as_a_warning(): void
{
    $warnings = [];

    $logger = new class($warnings) extends AbstractLogger {
        public function __construct(private array &$log) {}

        public function log($level, string|\Stringable $message, array $context = []): void
        {
            $this->log[] = ['level' => $level, 'message' => $message];
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
    self::assertStringContainsString('1', $warnings[0]['message'],
        'Het aantal overgeslagen aanbiedingen staat in de logmelding');
}
```

- [ ] **Stap 2: draai de test om te bevestigen dat hij faalt**

```
docker compose exec -T php php bin/phpunit tests/Infrastructure/Source/BrightDataFlightSourceTest.php --filter test_skipped_offers_are_logged_as_a_warning
```

Verwacht: FAIL of ERROR (constructor accepteert geen logger-argument).

- [ ] **Stap 3: voeg de logger toe aan `BrightDataFlightSource`**

Wijzig de constructor van `BrightDataFlightSource` als volgt:

```php
use Psr\Log\LoggerInterface;

// In de constructor:
public function __construct(
    private HttpClientInterface $http,
    private string $apiToken,
    private string $zone,
    private ?LoggerInterface $logger = null,
) {
}
```

Wijzig de `search()`-methode om overgeslagen aanbiedingen bij te houden:

```php
    public function search(Airport $from, Airport $to, \DateTimeImmutable $date): array
    {
        $response = $this->http->request('POST', self::ENDPOINT, [
            'headers' => [
                'Authorization: Bearer '.$this->apiToken,
                'Content-Type: application/json',
            ],
            'json' => [
                'zone' => $this->zone,
                'format' => 'json',
                'url' => $this->googleFlightsUrl($from, $to, $date),
            ],
        ]);

        $payload = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        $raw = [
            ...($payload['best_flights'] ?? []),
            ...($payload['other_flights'] ?? []),
        ];

        $itineraries = [];
        $skipped = 0;

        foreach ($raw as $offer) {
            $itinerary = $this->toItinerary($offer);

            if (null !== $itinerary) {
                $itineraries[] = $itinerary;
            } else {
                ++$skipped;
            }
        }

        if ($skipped > 0) {
            $this->logger?->warning(sprintf(
                '%d aanbod(en) overgeslagen voor route %s→%s (%s).',
                $skipped,
                $from->value,
                $to->value,
                $date->format('Y-m-d'),
            ));
        }

        return $itineraries;
    }
```

- [ ] **Stap 4: bedraat de logger in `api/config/services.yaml`**

Symfony's autowiring koppelt automatisch `LoggerInterface` via de `_defaults: autowire: true` instelling. Omdat de parameter nullable is (`?LoggerInterface`) en Symfony een logger beschikbaar heeft via `monolog.logger`, werkt autowiring hier out-of-the-box. Geen expliciete configuratie nodig.

Controleer dit door de app te starten (optioneel in CI; de integratietests draaien dit impliciet).

- [ ] **Stap 5: draai alle tests**

```
docker compose exec -T php php bin/phpunit
```

Verwacht: 86 + 1 nieuwe = 87 tests groen.

- [ ] **Stap 6: commit**

```bash
git add api/src/Infrastructure/Source/BrightDataFlightSource.php \
        api/tests/Infrastructure/Source/BrightDataFlightSourceTest.php

git commit -m "$(cat <<'EOF'
feat(adapter): log overgeslagen aanbiedingen als warning

Rotte aanbiedingen werden stil overgeslagen. Als Bright Data van formaat
verandert, krijg je nul resultaten zonder enige aanwijzing waarom. Nu wordt
één warning gelogd met het aantal en de route zodra er één of meer
aanbiedingen worden overgeslagen.

Logger is nullable (standaard null) zodat bestaande tests ongewijzigd blijven.
EOF
)"
```

---

### Task 4: Bevinding 4 — brondatafouten gepresenteerd als invoerfout (400)

**Files:**
- Create: `api/src/Domain/Traveller/UnknownTraveller.php`
- Modify: `api/src/Domain/Traveller/Traveller.php` (gooit UnknownTraveller i.p.v. \InvalidArgumentException)
- Modify: `api/src/Infrastructure/Http/SearchController.php` (vangt alleen UnknownTraveller af)
- Modify: `api/tests/Infrastructure/Http/SearchControllerTest.php` (bestaande test blijft groen; nieuwe 500-test toevoegen)
- Modify: `api/tests/Domain/Traveller/TravellerTest.php` (bestaande test controleert nu UnknownTraveller)

**Interfaces:**
- Produces: `UnknownTraveller extends \InvalidArgumentException` in namespace `App\Domain\Traveller`
- Consumes: controller vangt alleen `UnknownTraveller` af → 400; al het andere → 500

**Wat er mis was:** `SearchController` vangt alle `\InvalidArgumentException` af en geeft ze als HTTP 400 terug. Maar `Money` en `Itinerary` gooien dezelfde soort uitzondering bij kapotte brondata. Resultaat: een serverfout wordt gepresenteerd als invoerfout van de gebruiker, en de fout blijft onopgemerkt.

**Domeinpuurheid:** `UnknownTraveller` hoort in `src/Domain/Traveller/` en mag geen framework-imports bevatten. `\InvalidArgumentException` is PHP core, dus geen probleem.

- [ ] **Stap 1: schrijf de falende test voor de controller (500 bij brondatafout)**

Voeg onderstaande test toe aan `api/tests/Infrastructure/Http/SearchControllerTest.php`. De bestaande test `test_an_unknown_traveller_is_a_bad_request` moet groen blijven.

Voeg bovenaan de imports toe die nog ontbreken:
```php
use App\Domain\Flight\Direction;
```

Test:

```php
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
```

- [ ] **Stap 2: draai de test om te bevestigen dat hij faalt**

```
docker compose exec -T php php bin/phpunit tests/Infrastructure/Http/SearchControllerTest.php --filter test_a_data_source_error_is_a_server_error
```

Verwacht: FAIL (krijgt 400 i.p.v. 500).

- [ ] **Stap 3: maak `UnknownTraveller` aan**

Maak het bestand `api/src/Domain/Traveller/UnknownTraveller.php` aan:

```php
<?php

declare(strict_types=1);

namespace App\Domain\Traveller;

final class UnknownTraveller extends \InvalidArgumentException
{
    public static function withId(string $id): self
    {
        return new self(sprintf('Onbekende reiziger "%s".', $id));
    }
}
```

- [ ] **Stap 4: laat `Traveller::byId()` UnknownTraveller gooien**

Vervang in `api/src/Domain/Traveller/Traveller.php` de regel:

```php
            default => throw new \InvalidArgumentException(sprintf('Onbekende reiziger "%s".', $id)),
```

Door:

```php
            default => throw UnknownTraveller::withId($id),
```

- [ ] **Stap 5: vang in de controller alleen `UnknownTraveller` af**

Vervang in `api/src/Infrastructure/Http/SearchController.php`:

```php
use App\Domain\Traveller\UnknownTraveller;
```

(voeg toe bovenaan, naast de andere use-statements)

Vervang:

```php
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 400);
        }
```

Door:

```php
        } catch (UnknownTraveller $e) {
            return new JsonResponse(['error' => $e->getMessage()], 400);
        }
```

- [ ] **Stap 6: update de bestaande test in `TravellerTest`**

De bestaande test `test_unknown_id_is_rejected` verwacht `\InvalidArgumentException`. Omdat `UnknownTraveller extends \InvalidArgumentException`, blijft die test groen zonder aanpassing. Verifieer dit in stap 7.

Optioneel: je kunt de test preciezer maken door `UnknownTraveller::class` te verwachten. Maar de opdracht zegt "bestaande test blijft groen" — geen aanpassing nodig.

- [ ] **Stap 7: draai alle tests**

```
docker compose exec -T php php bin/phpunit
```

Verwacht: 87 + 1 nieuwe = 88 tests groen. Controleer specifiek:
- `test_an_unknown_traveller_is_a_bad_request` → groen (400)
- `test_a_data_source_error_is_a_server_error` → groen (500)
- `DomainPurityTest` → groen (geen framework-imports in Domain)

- [ ] **Stap 8: commit**

```bash
git add api/src/Domain/Traveller/UnknownTraveller.php \
        api/src/Domain/Traveller/Traveller.php \
        api/src/Infrastructure/Http/SearchController.php \
        api/tests/Infrastructure/Http/SearchControllerTest.php

git commit -m "$(cat <<'EOF'
fix(controller): brondatafouten niet langer als invoerfout presenteren

De controller ving alle \InvalidArgumentException af en gaf ze als 400 terug.
Money en Itinerary gooien dezelfde soort uitzondering bij kapotte brondata,
waardoor een serverfout als gebruikersfout verscheen en de fout verborgen bleef.

Fix: nieuw type UnknownTraveller (extends \InvalidArgumentException) in de
domeinlaag. Controller vangt alleen dat type af voor de 400-respons.
Alles anders wordt een 500, conform HTTP-semantiek.
EOF
)"
```

---

### Task 5: Rapportage schrijven

**Files:**
- Write: `/Storage/Workspace/Projecten/flights/.superpowers/sdd/eindreview-fixes-report.md`

**Interfaces:**
- Consumes: resultaten van tasks 1–4 (commit-hashes, testuitslag, mutatietestresultaten)
- Produces: volledig verslag per bevinding

- [ ] **Stap 1: haal commit-hashes op**

```
git log --oneline -4
```

- [ ] **Stap 2: draai de eindtestrun**

```
docker compose exec -T php php bin/phpunit --testdox 2>&1
```

- [ ] **Stap 3: schrijf het rapport**

Schrijf naar `/Storage/Workspace/Projecten/flights/.superpowers/sdd/eindreview-fixes-report.md` met per bevinding:
- Wat er mis was (kort)
- Welke bestanden zijn gewijzigd
- De gedraaide commands met hun uitvoer
- De mutatietestresultaten (welke test pakt welke mutant)
- De commit-hash

Gebruik het formaat dat de gebruiker verwacht: duidelijk, bondig, in het Nederlands.

---

## Zelfcheck

**Spec-dekking:**
- Bevinding 1 (patroon terugreis): Task 1 — gedekt.
- Bevinding 2 (onleesbare prijs): Task 2 — gedekt, inclusief `N/A`, locale-notatie en negatief.
- Bevinding 3 (geen logging): Task 3 — gedekt.
- Bevinding 4 (400 vs 500): Task 4 — gedekt, inclusief `UnknownTraveller` in domein.
- Mutatietests: Tasks 1 en 2 bevatten expliciete mutatietest-stappen.
- Commit per bevinding: Tasks 1–4 eindigen elk met een commit.
- Rapport: Task 5.
- 80 bestaande tests blijven groen: elke taak eindigt met een volledige testrun.

**Placeholder-scan:** geen TBD, geen "add appropriate error handling", alle code is uitgeschreven.

**Type-consistentie:**
- `RoutePattern::ViaHelsinkiWithTrain` — consistent in adapter en tests.
- `UnknownTraveller::withId(string $id): self` — gecreëerd in Task 4 stap 3, gebruikt in stap 4.
- `BrightDataFlightSource` constructor heeft 4e argument `?LoggerInterface $logger = null` — consistent tussen Task 3 (implementatie) en Task 3 (test).

**Aandachtspunt over Task 3:** de bestaande tests in `BrightDataFlightSourceTest` maken een `BrightDataFlightSource` aan via de `sourceReturning()`-hulpmethode zonder een logger mee te geven. Na de wijziging in stap 3 heeft de constructor `?LoggerInterface $logger = null` — dat is nullable met default null, dus de bestaande aanroepen in `sourceReturning()` blijven werken zonder aanpassing. Controleer dit na task 3.

**Aandachtspunt over bagageberekening in Task 1:** de verwachte totaalprijzen in `test_return_flight_via_helsinki_includes_train_cost` zijn gebaseerd op de aanname dat Finnair Silver gratis bagage geeft voor Chris. Lees `TotalCostCalculator` en `BaggagePolicy` voor de echte waarden voordat je de test schrijft, en pas de `assertSame`-waarden aan.
