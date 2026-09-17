# Rangschikking met treinprijsschatting — Implementatieplan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Wanneer de treinprijs onbekend is, wordt voor de sortering een vaste schatting gebruikt zodat de "via Helsinki"-optie niet onterecht bovenaan staat; het getoonde totaal blijft ongewijzigd.

**Architecture:** `TotalCost` krijgt een methode `rankingTotal(Money $trainEstimate)` die alleen de sorteervolgorde beïnvloedt. `SearchFlightsHandler` ontvangt de schattingswaarde via de constructor (geconfigureerd in `services.yaml`) en sorteert op `rankingTotal()` in plaats van `total()`. `total()` is ongewijzigd — dat is wat de gebruiker ziet.

**Tech Stack:** PHP 8.4, Symfony 7.4, PHPUnit, `declare(strict_types=1)`, geldbedragen als `Money` (int centen).

## Global Constraints

- `declare(strict_types=1);` in elk PHP-bestand
- `src/Domain/**` framework-vrij (DomainPurityTest controleert op Symfony/Doctrine/Psr/Twig imports)
- Geldbedragen altijd in hele centen als int — gebruik `Money::fromCents()` of `Money::fromEuros()`
- Alle 126 bestaande PHP-tests en 9 frontend-tests blijven groen
- Het getoonde totaal (`total()`) mag niet veranderen — geen bedrag verzinnen op het scherm
- Env-variabele `TRAIN_PRICE_ESTIMATE=35` (euro's, integer)
- Geen plandocument toevoegen aan `docs/` (dit plan is de uitzondering — het is het plan zelf)

---

### Task 1: Configuratie — `TRAIN_PRICE_ESTIMATE` env-variabele

**Files:**
- Modify: `api/.env`
- Modify: `api/config/services.yaml`
- Modify: `README.md`

**Interfaces:**
- Produces: env-variabele `TRAIN_PRICE_ESTIMATE` beschikbaar als `%env(float:TRAIN_PRICE_ESTIMATE)%` in services.yaml

- [ ] **Step 1: Voeg de variabele toe aan `api/.env`**

Voeg onderaan het bestand toe (na `FARE_UPGRADE_KL=25`):

```
TRAIN_PRICE_ESTIMATE=35
```

- [ ] **Step 2: Documenteer de variabele in `README.md`**

Voeg een rij toe aan de tabel in de sectie "Instellen", na de `FARE_UPGRADE_KL`-rij:

```markdown
| `TRAIN_PRICE_ESTIMATE` | Schatting treinprijs HEL⇄TKU in euro's — **35** (alleen voor sortering bij onbekende prijs) |
```

- [ ] **Step 3: Bedraden in `api/config/services.yaml`**

Voeg onder het `App\Domain\Pricing\FareUpgradeRates:`-blok een nieuw service-definitie toe voor `SearchFlightsHandler`. Pas het bestaande patroon na: de schatting wordt als `Money` doorgegeven, dus voeg een expliciete argument-binding toe. Maar omdat `Money` geen Symfony-service is, moet de handler de ruwe float ontvangen en zelf omzetten — of we maken een factory. Echter: het domein mag geen configuratie kennen. De handler zit in `Application`, niet in `Domain`, dus mag hij Symfony kennen. De eenvoudigste aanpak: geef `$trainPriceEstimate` als `Money`-object mee via een factory-methode of via een explicit argument als int-centen.

Eenvoudigste aanpak (geen factory nodig): definieer een named argument `$trainPriceEstimate` die we als tagged service wired. Maar `Money` is geen service. Dus: voeg een `parameters`-sectie toe:

```yaml
parameters:
    app.train_price_estimate_cents: '%env(int:TRAIN_PRICE_ESTIMATE)%'
```

En bedraden de handler expliciet:

```yaml
App\Application\SearchFlights\SearchFlightsHandler:
    arguments:
        $trainPriceEstimate: !php/const App\Domain\Flight\Money::fromCents('%app.train_price_estimate_cents%')
```

Dat werkt niet — `fromCents()` is geen constante. De juiste aanpak voor Symfony: gebruik een `bind` of geef de waarde als integer via een parameter door en laat de handler zelf `Money::fromCents()` aanroepen. Maar de handler zou dan `Money` kennen, wat prima is (het is een domeintype, geen framework). De handler heeft `Money` al nodig als type hint.

Gebruik dus gewoon:

```yaml
parameters:
    app.train_price_estimate_cents: '%env(int:TRAIN_PRICE_ESTIMATE)%'

App\Application\SearchFlights\SearchFlightsHandler:
    arguments:
        $trainPriceEstimateCents: '%app.train_price_estimate_cents%'
```

En de handler-constructor ontvangt `int $trainPriceEstimateCents` en doet zelf `Money::fromCents($trainPriceEstimateCents)` in de constructor-body.

- [ ] **Step 4: Verifieer dat de container nog compileert (geen PHP-tests nodig)**

```bash
docker compose exec -T php php bin/console cache:clear 2>&1 | tail -5
```

Verwacht: geen fout, of later — na de handler-wijziging — wel een fout als de constructor nog niet klopt. Dit is een tussenstap; voer het uit na Task 2.

---

### Task 2: `TotalCost::rankingTotal()` methode

**Files:**
- Modify: `api/src/Domain/Pricing/TotalCost.php`
- Modify: `api/tests/Domain/Pricing/TotalCostCalculatorTest.php` (of maak een aparte `TotalCostTest.php`)

**Interfaces:**
- Consumes: `TotalCost` met bestaande velden `total(): Money`, `trainCostKnown: bool`
- Produces: `TotalCost::rankingTotal(Money $trainEstimate): Money` — geeft `total()` wanneer `trainCostKnown === true`, anders `total()->add($trainEstimate)`

- [ ] **Step 1: Schrijf de falende tests**

Voeg toe aan `api/tests/Domain/Pricing/TotalCostCalculatorTest.php` (of maak `TotalCostTest.php` — gebruik de bestaande `TotalCostCalculatorTest.php` als die al bestaat en het gemakkelijker is):

Maak een nieuw testbestand `api/tests/Domain/Pricing/TotalCostTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Domain\Pricing;

use App\Domain\Flight\Money;
use App\Domain\Pricing\TotalCost;
use PHPUnit\Framework\TestCase;

final class TotalCostTest extends TestCase
{
    public function test_ranking_total_uses_estimate_when_train_cost_unknown(): void
    {
        $cost = new TotalCost(
            basePrice:      Money::fromEuros(140.00),
            baggageCost:    Money::zero(),
            trainCost:      Money::zero(),
            indicative:     true,
            trainCostKnown: false,
        );

        $estimate = Money::fromEuros(35.00);

        self::assertSame(17500, $cost->rankingTotal($estimate)->cents,
            '€140 + schatting €35 = €175 voor rangschikking');
    }

    public function test_ranking_total_equals_total_when_train_cost_known(): void
    {
        $cost = new TotalCost(
            basePrice:      Money::fromEuros(134.00),
            baggageCost:    Money::zero(),
            trainCost:      Money::fromEuros(22.50),
            indicative:     false,
            trainCostKnown: true,
        );

        $estimate = Money::fromEuros(35.00);

        self::assertSame($cost->total()->cents, $cost->rankingTotal($estimate)->cents,
            'bij bekende treinprijs: rankingTotal == total, schatting niet gebruikt');
    }

    public function test_total_is_unchanged_when_train_cost_unknown(): void
    {
        // Bewijst dat total() zelf niet verandert — het getoonde bedrag blijft €140
        $cost = new TotalCost(
            basePrice:      Money::fromEuros(140.00),
            baggageCost:    Money::zero(),
            trainCost:      Money::zero(),
            indicative:     true,
            trainCostKnown: false,
        );

        self::assertSame(14000, $cost->total()->cents,
            'total() toont €140 — de schatting mag hier niet zichtbaar zijn');
    }
}
```

- [ ] **Step 2: Draai de tests om te verifiëren dat ze falen**

```bash
docker compose exec -T php php bin/phpunit tests/Domain/Pricing/TotalCostTest.php --testdox 2>&1 | tail -20
```

Verwacht: FAIL — "Call to undefined method App\Domain\Pricing\TotalCost::rankingTotal()"

- [ ] **Step 3: Implementeer `rankingTotal()` in `TotalCost`**

Voeg de methode toe aan `api/src/Domain/Pricing/TotalCost.php`:

```php
public function rankingTotal(Money $trainEstimate): Money
{
    if ($this->trainCostKnown) {
        return $this->total();
    }

    return $this->total()->add($trainEstimate);
}
```

Het volledige bestand wordt:

```php
<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

use App\Domain\Flight\Money;

final readonly class TotalCost
{
    public function __construct(
        public Money $basePrice,
        public Money $baggageCost,
        public Money $trainCost,
        public bool $indicative,
        public bool $trainCostKnown,
    ) {
    }

    public function total(): Money
    {
        return $this->basePrice->add($this->baggageCost)->add($this->trainCost);
    }

    public function rankingTotal(Money $trainEstimate): Money
    {
        if ($this->trainCostKnown) {
            return $this->total();
        }

        return $this->total()->add($trainEstimate);
    }
}
```

- [ ] **Step 4: Draai de tests om te verifiëren dat ze slagen**

```bash
docker compose exec -T php php bin/phpunit tests/Domain/Pricing/TotalCostTest.php --testdox 2>&1 | tail -20
```

Verwacht: 3 tests PASS

- [ ] **Step 5: Draai alle PHP-tests om te controleren dat er geen regressies zijn**

```bash
docker compose exec -T php php bin/phpunit 2>&1 | tail -10
```

Verwacht: 129 tests, 0 failures (was 126; nu +3 nieuwe)

- [ ] **Step 6: Commit**

```bash
git -C /Storage/Workspace/Projecten/flights add api/src/Domain/Pricing/TotalCost.php api/tests/Domain/Pricing/TotalCostTest.php
git -C /Storage/Workspace/Projecten/flights commit -m "feat(domain): add TotalCost::rankingTotal() for sorting with train price estimate

When the train price is unknown, rankingTotal() adds a configurable estimate
so that via-Helsinki options sort correctly against through-tickets.
total() is unchanged — the displayed amount never shows the estimate."
```

---

### Task 3: `SearchFlightsHandler` sorteert op `rankingTotal()`

**Files:**
- Modify: `api/src/Application/SearchFlights/SearchFlightsHandler.php`
- Modify: `api/config/services.yaml`
- Modify: `api/.env`
- Modify: `README.md`
- Modify: `api/tests/Application/SearchFlights/SearchFlightsHandlerTest.php`

**Interfaces:**
- Consumes: `TotalCost::rankingTotal(Money $trainEstimate): Money` (geproduceerd in Task 2)
- Consumes: `Money::fromCents(int): Money` (bestaand)
- Produces: `SearchFlightsHandler` met constructor-parameter `int $trainPriceEstimateCents`

- [ ] **Step 1: Schrijf de falende handler-tests**

Voeg toe aan `api/tests/Application/SearchFlights/SearchFlightsHandlerTest.php`:

```php
public function test_unknown_train_price_uses_estimate_for_sorting_not_display(): void
{
    // Scenario uit de spec: Finnair via HEL €140 met onbekende treinprijs staat
    // ONDER SAS doorgaand €150, omdat €140 + schatting €35 = €175 > €150.
    // Maar het getoonde totaal van Finnair blijft €140, niet €175.
    $flights = new InMemoryFlightPriceSource();
    $flights->give(Airport::Ams, Airport::Hel, [
        self::viaHel(Carrier::Finnair, 140, '13:00', '16:45'),
    ]);
    $flights->give(Airport::Ams, Airport::Tku, [
        self::throughTicket(Carrier::Sas, 150),
    ]);

    $result = $this->handlerWithEstimate($flights, FixedTrainPriceSource::withUnknownPrice(), 3500)(
        new SearchFlightsQuery(new \DateTimeImmutable('2026-10-29'), Direction::NlToFi, 'chris'),
    );

    self::assertCount(2, $result->options);
    // SAS staat eerste (€150 < €175 rangschikking)
    self::assertSame(15000, $result->options[0]->cost->total()->cents,
        'SAS doorgaand staat eerste: €150');
    // Finnair staat tweede, maar het GETOONDE totaal is €140, niet €175
    self::assertSame(14000, $result->options[1]->cost->total()->cents,
        'Finnair getoond als €140 — de schatting is NIET zichtbaar in total()');
}

public function test_known_train_price_sorting_unchanged(): void
{
    // Geen regressie: bij bekende treinprijs verandert de volgorde niet.
    $flights = new InMemoryFlightPriceSource();
    $flights->give(Airport::Ams, Airport::Hel, [
        self::viaHel(Carrier::Finnair, 134, '13:00', '16:45'),
    ]);
    $flights->give(Airport::Ams, Airport::Tku, [
        self::throughTicket(Carrier::Sas, 150),
    ]);

    $result = $this->handlerWithEstimate($flights, FixedTrainPriceSource::withPrice(Money::fromEuros(22.50)), 3500)(
        new SearchFlightsQuery(new \DateTimeImmutable('2026-10-29'), Direction::NlToFi, 'chris'),
    );

    self::assertCount(2, $result->options);
    // SAS €150 staat eerste, Finnair €134+€22.50=€156.50 staat tweede
    self::assertSame(15000, $result->options[0]->cost->total()->cents, 'SAS: €150');
    self::assertSame(15650, $result->options[1]->cost->total()->cents, 'Finnair: €156,50');
}
```

En voeg de helper toe aan de klasse:

```php
private function handlerWithEstimate(
    InMemoryFlightPriceSource $flights,
    FixedTrainPriceSource $train,
    int $estimateCents,
): SearchFlightsHandler {
    $rates = FareUpgradeRates::fromConfig(['AY' => 35, 'KL' => 40]);

    return new SearchFlightsHandler(
        $flights,
        $train,
        new TotalCostCalculator(new BaggagePolicy($rates), $rates),
        new NullPriceHistory(),
        $estimateCents,
    );
}
```

- [ ] **Step 2: Draai de tests om te verifiëren dat ze falen**

```bash
docker compose exec -T php php bin/phpunit tests/Application/SearchFlights/SearchFlightsHandlerTest.php --testdox 2>&1 | tail -20
```

Verwacht: FAIL — constructor heeft nog geen `$trainPriceEstimateCents`

- [ ] **Step 3: Pas `SearchFlightsHandler` aan**

Vervang de constructor en de `usort`-aanroep in `api/src/Application/SearchFlights/SearchFlightsHandler.php`:

```php
<?php

declare(strict_types=1);

namespace App\Application\SearchFlights;

use App\Domain\Flight\Airport;
use App\Domain\Flight\Itinerary;
use App\Domain\Flight\Money;
use App\Domain\Flight\RoutePattern;
use App\Domain\Port\FlightPriceSource;
use App\Domain\Port\PriceHistory;
use App\Domain\Port\TrainPriceSource;
use App\Domain\Pricing\TotalCostCalculator;
use App\Domain\Rules\RuleSet;
use App\Domain\Traveller\Traveller;

final readonly class SearchFlightsHandler
{
    private Money $trainPriceEstimate;

    public function __construct(
        private FlightPriceSource $flights,
        private TrainPriceSource $trains,
        private TotalCostCalculator $calculator,
        private PriceHistory $history,
        int $trainPriceEstimateCents,
    ) {
        $this->trainPriceEstimate = Money::fromCents($trainPriceEstimateCents);
    }

    public function __invoke(SearchFlightsQuery $query): SearchResult
    {
        $traveller = Traveller::byId($query->travellerId);
        $rules = RuleSet::standard($query->direction);
        $searchedAt = new \DateTimeImmutable();

        $candidates = [
            ...$this->fetch(Airport::Hel, $query),
            ...$this->fetch(Airport::Tku, $query),
        ];

        $valid = $rules->filter($candidates);

        // Alleen betalen voor een treinprijs als er een HEL-optie overblijft.
        $trainCost = $this->needsTrainPrice($valid)
            ? $this->trains->priceFor($query->date)
            : null;

        $options = [];

        foreach ($valid as $itinerary) {
            $this->history->record($itinerary, $searchedAt);

            $options[] = new PricedItinerary(
                $itinerary,
                $this->calculator->calculate($itinerary, $traveller, $trainCost),
            );
        }

        $estimate = $this->trainPriceEstimate;

        usort(
            $options,
            static fn (PricedItinerary $a, PricedItinerary $b): int
                => $a->cost->rankingTotal($estimate)->cents <=> $b->cost->rankingTotal($estimate)->cents,
        );

        return new SearchResult($options, $traveller, $searchedAt);
    }

    /**
     * @return Itinerary[]
     */
    private function fetch(Airport $finnishEnd, SearchFlightsQuery $query): array
    {
        return $query->direction->isOutbound()
            ? $this->flights->search(Airport::Ams, $finnishEnd, $query->date)
            : $this->flights->search($finnishEnd, Airport::Ams, $query->date);
    }

    /**
     * @param Itinerary[] $itineraries
     */
    private function needsTrainPrice(array $itineraries): bool
    {
        foreach ($itineraries as $itinerary) {
            if (RoutePattern::ViaHelsinkiWithTrain === $itinerary->pattern) {
                return true;
            }
        }

        return false;
    }
}
```

- [ ] **Step 4: Pas de bestaande `handler()` helper aan in de test**

De bestaande `handler()` helper roept `new SearchFlightsHandler(...)` aan zonder de 5e parameter. Dat breekt de bestaande tests. Voeg een default-waarde toe door de helper aan te passen:

```php
private function handler(InMemoryFlightPriceSource $flights, FixedTrainPriceSource $train): SearchFlightsHandler
{
    $rates = FareUpgradeRates::fromConfig(['AY' => 35, 'KL' => 40]);

    return new SearchFlightsHandler(
        $flights,
        $train,
        new TotalCostCalculator(new BaggagePolicy($rates), $rates),
        new NullPriceHistory(),
        3500, // €35 schatting — doet er niet toe als de treinprijs bekend is
    );
}
```

En de `test_real_adapter_hel_to_ams_finnair_gets_train_pattern_and_correct_total` test instantieert ook direct de handler — pas die aan:

```php
$handler = new SearchFlightsHandler(
    $source,
    FixedTrainPriceSource::withPrice(Money::fromEuros(22.50)),
    new TotalCostCalculator(new BaggagePolicy($rates), $rates),
    new NullPriceHistory(),
    3500, // €35 schatting
);
```

- [ ] **Step 5: Pas `services.yaml` aan**

Voeg aan `api/config/services.yaml` toe:

```yaml
parameters:
    app.train_price_estimate_cents: '%env(int:TRAIN_PRICE_ESTIMATE)%'
```

En voeg een expliciete service-definitie toe voor de handler:

```yaml
App\Application\SearchFlights\SearchFlightsHandler:
    arguments:
        $trainPriceEstimateCents: '%app.train_price_estimate_cents%'
```

- [ ] **Step 6: Draai alle PHP-tests**

```bash
docker compose exec -T php php bin/phpunit 2>&1 | tail -15
```

Verwacht: 131 tests (was 126, +3 uit Task 2, +2 nieuwe), 0 failures

- [ ] **Step 7: Mutatietest — verifieer dat de sorteertest scherp is**

Wijzig tijdelijk in `SearchFlightsHandler.php` de sortering terug naar `total()`:

```php
// MUTANT — tijdelijk:
=> $a->cost->total()->cents <=> $b->cost->total()->cents,
```

Draai alleen de handler-tests:

```bash
docker compose exec -T php php bin/phpunit tests/Application/SearchFlights/SearchFlightsHandlerTest.php --testdox 2>&1 | tail -20
```

Verwacht: `test_unknown_train_price_uses_estimate_for_sorting_not_display` FAALT
(Finnair €140 staat vóór SAS €150 wanneer sortering op `total()` teruggaat)

Zet terug naar `rankingTotal()`.

- [ ] **Step 8: Draai alle PHP-tests nogmaals na het terugzetten**

```bash
docker compose exec -T php php bin/phpunit 2>&1 | tail -10
```

Verwacht: 131 tests, 0 failures

- [ ] **Step 9: Draai frontend-tests**

```bash
docker compose run --rm node npm run test 2>&1 | tail -10
```

Verwacht: 9 tests, 0 failures

- [ ] **Step 10: Commit**

```bash
git -C /Storage/Workspace/Projecten/flights add \
    api/src/Application/SearchFlights/SearchFlightsHandler.php \
    api/tests/Application/SearchFlights/SearchFlightsHandlerTest.php \
    api/config/services.yaml \
    api/.env \
    README.md
git -C /Storage/Workspace/Projecten/flights commit -m "fix(sort): use train price estimate for ranking when actual price is unknown

Without this fix, a via-Helsinki flight at €140 with unknown train price sorted
above a through-ticket at €150, because the missing price was treated as zero.
In reality the train costs ~€22.50, making the Helsinki option more expensive.

Now when trainCostKnown=false, rankingTotal() adds a configurable estimate
(TRAIN_PRICE_ESTIMATE, default €35) so the sort order is correct. The displayed
total() is unchanged — no invented amount is shown to the user."
```

---

### Task 4: Verslag schrijven

**Files:**
- Write: `/Storage/Workspace/Projecten/flights/.superpowers/sdd/rangschikking-report.md`

- [ ] **Step 1: Schrijf het verslag met commando's, uitvoer en mutatietestresultaat**

Inhoud: wat er gedaan is, de commandoregels met hun uitvoer (inclusief testresultaten), en het mutatietestresultaat (welke test faalde, en hoe).
