# Treinprijs Onbekend Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Vier losse fixes doorvoeren zodat een ontbrekende treinprijs nooit stilzwijgend als €0 wordt behandeld, mislukte Apify-aanroepen kort worden gecacht, `Money` zijn eigen randvoorwaarden bewaakt, en `UnknownTraveller` het juiste basistype heeft.

**Architecture:** Domeinlaag (`src/Domain/**`) is framework-vrij PHP; infrastructuurlaag (`src/Infrastructure/**`) gebruikt Symfony + PSR. De Vue 3 SPA communiceert via een JSON-API. Elke wijziging is één commit; tests first.

**Tech Stack:** PHP 8.4, Symfony 7.4, PHPUnit (in Docker: `docker compose exec -T php php bin/phpunit`), Vue 3 + TypeScript, Vitest (`docker compose run --rm node npm run test`), Docker Compose.

## Global Constraints

- `declare(strict_types=1);` in elk PHP-bestand
- `src/Domain/**` bevat geen Symfony/Doctrine/Psr/Twig-imports (DomainPurityTest controleert dit)
- Geldbedragen altijd als gehele centen (`int`)
- TypeScript strict, geen `any`
- Geen netwerktoegang in tests
- PHP-tests: `docker compose exec -T php php bin/phpunit` vanuit `/Storage/Workspace/Projecten/flights`
- Frontend-tests: `docker compose run --rm node npm run test` vanuit `/Storage/Workspace/Projecten/flights`
- Commit per wijziging afzonderlijk

---

## File Map

| Bestand | Actie | Reden |
|---------|-------|-------|
| `api/src/Domain/Port/TrainPriceSource.php` | Modify | Return type `Money` → `?Money` |
| `api/src/Infrastructure/Source/ApifyTrainPriceSource.php` | Modify | `null` teruggeven bij leeg; null 15 min cachen |
| `api/src/Domain/Pricing/TotalCost.php` | Modify | Veld `trainCostKnown: bool` toevoegen |
| `api/src/Domain/Pricing/TotalCostCalculator.php` | Modify | `null`-treinprijs → `trainCostKnown=false`, `indicative=true` |
| `api/src/Infrastructure/Http/SearchResultPresenter.php` | Modify | `trainCostKnown` in JSON-output |
| `api/src/Domain/Flight/Money.php` | Modify | `fromEuros()` gooit bij INF/NAN |
| `api/src/Domain/Traveller/UnknownTraveller.php` | Modify | Erft van `\DomainException` i.p.v. `\InvalidArgumentException` |
| `api/tests/Double/FixedTrainPriceSource.php` | Modify | Optioneel `?Money` ondersteunen (null-variant toevoegen) |
| `web/src/types.ts` | Modify | `trainCostKnown: boolean` in `Cost` |
| `web/src/components/OptionCard.vue` | Modify | Treinregel toont "onbekend" als `trainCostKnown === false` |
| `api/tests/Infrastructure/Source/ApifyTrainPriceSourceTest.php` | Modify | Nieuwe tests: lege respons → null, null gecacht 15 min, twee calls bij kapotte bron → één call |
| `api/tests/Domain/Pricing/TotalCostCalculatorTest.php` | Modify | Nieuwe tests: null-treinprijs → `trainCostKnown=false`, `indicative=true`; pattern B → `trainCostKnown=true` |
| `api/tests/Infrastructure/Http/SearchControllerTest.php` | Modify | Test dat `trainCostKnown` in JSON zit |
| `api/tests/Domain/Flight/MoneyTest.php` | Modify | Tests voor INF, -INF, NAN |
| `web/src/components/__tests__/OptionCard.spec.ts` | Modify | Test `trainCostKnown: false` → "onbekend" + indicatief |

---

## Task 1: Wijziging 4 — `UnknownTraveller` erft van `\DomainException`

Dit is de kleinste wijziging, helemaal op zichzelf, en goede opwarming.

**Files:**
- Modify: `api/src/Domain/Traveller/UnknownTraveller.php`
- Test: `api/tests/Infrastructure/Http/SearchControllerTest.php` (bestaande test `test_an_unknown_traveller_is_a_bad_request` moet groen blijven)

**Interfaces:**
- Consumes: niets nieuws
- Produces: `UnknownTraveller extends \DomainException` — `SearchController` vangt hem via `catch (UnknownTraveller $e)`, wat hetzelfde blijft

- [ ] **Stap 1: Run bestaande test om baseline vast te leggen**

```bash
docker compose exec -T php php bin/phpunit tests/Infrastructure/Http/SearchControllerTest.php --filter test_an_unknown_traveller_is_a_bad_request
```

Verwacht: 1 test, groen.

- [ ] **Stap 2: Pas `UnknownTraveller` aan**

Huidige inhoud van `api/src/Domain/Traveller/UnknownTraveller.php`:
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

Nieuwe inhoud — enkel de extends-regel verandert:
```php
<?php

declare(strict_types=1);

namespace App\Domain\Traveller;

final class UnknownTraveller extends \DomainException
{
    public static function withId(string $id): self
    {
        return new self(sprintf('Onbekende reiziger "%s".', $id));
    }
}
```

- [ ] **Stap 3: Controleer dat SearchController hem nog steeds opvangt**

`SearchController` heeft `catch (UnknownTraveller $e)` — dat werkt op het concrete type, ongeacht de ancestor. Geen aanpassing nodig.

- [ ] **Stap 4: Run alle PHP-tests**

```bash
docker compose exec -T php php bin/phpunit
```

Verwacht: 103 tests groen, 0 failures.

- [ ] **Stap 5: Commit**

```bash
git add api/src/Domain/Traveller/UnknownTraveller.php
git commit -m "fix(domain): UnknownTraveller erft van DomainException in plaats van InvalidArgumentException

InvalidArgumentException is gereserveerd voor kapotte brondata (Money, Itinerary).
DomainException drukt uit dat de gebruiker iets onmogelijks vroeg.
Met InvalidArgumentException berustte het onderscheid op de volgorde van catch-blokken."
```

---

## Task 2: Wijziging 3 — `Money::fromEuros()` beschermt zichzelf

**Files:**
- Modify: `api/src/Domain/Flight/Money.php`
- Modify: `api/tests/Domain/Flight/MoneyTest.php`

**Interfaces:**
- Consumes: niets nieuws
- Produces: `Money::fromEuros(float $euros): self` gooit `\InvalidArgumentException` bij `INF`, `-INF`, of `NAN`

- [ ] **Stap 1: Schrijf de falende tests**

Voeg toe aan `api/tests/Domain/Flight/MoneyTest.php` na de bestaande tests:

```php
public function test_inf_is_rejected(): void
{
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('niet-eindige waarde');

    Money::fromEuros(INF);
}

public function test_negative_inf_is_rejected(): void
{
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('niet-eindige waarde');

    Money::fromEuros(-INF);
}

public function test_nan_is_rejected(): void
{
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('niet-eindige waarde');

    Money::fromEuros(NAN);
}
```

- [ ] **Stap 2: Run tests om te bevestigen dat ze falen**

```bash
docker compose exec -T php php bin/phpunit tests/Domain/Flight/MoneyTest.php --filter "test_inf_is_rejected|test_negative_inf_is_rejected|test_nan_is_rejected"
```

Verwacht: 3 failures (geen exception gegenereerd).

- [ ] **Stap 3: Pas `Money::fromEuros()` aan**

Huidige implementatie in `api/src/Domain/Flight/Money.php`:
```php
public static function fromEuros(float $euros): self
{
    return new self((int) round($euros * 100));
}
```

Nieuwe implementatie:
```php
public static function fromEuros(float $euros): self
{
    if (!is_finite($euros)) {
        throw new \InvalidArgumentException(
            sprintf('Money::fromEuros() ontving een niet-eindige waarde (%s).', $euros),
        );
    }

    return new self((int) round($euros * 100));
}
```

- [ ] **Stap 4: Run de nieuwe tests**

```bash
docker compose exec -T php php bin/phpunit tests/Domain/Flight/MoneyTest.php
```

Verwacht: alle MoneyTest-tests groen.

- [ ] **Stap 5: Run alle PHP-tests om regressies te controleren**

```bash
docker compose exec -T php php bin/phpunit
```

Verwacht: 106 tests groen (103 + 3 nieuw), 0 failures.

- [ ] **Stap 6: Commit**

```bash
git add api/src/Domain/Flight/Money.php api/tests/Domain/Flight/MoneyTest.php
git commit -m "fix(domain): Money::fromEuros() gooit bij INF, -INF of NAN

(int) round(INF * 100) leverde stilzwijgend 0 op, wat een onzichtbare
fout introduceerde. Het type dat het geld bezit moet zijn eigen
randvoorwaarden bewaken; een aanroeper die hier later langskomt erft
de bescherming automatisch."
```

---

## Task 3: Wijziging 1 deel A — interface en domeintypen

Hier splitsen we de kern van wijziging 1 in twee stukken: eerst de interface + domeintypen (geen netwerktoegang), dan de infrastructuur.

**Files:**
- Modify: `api/src/Domain/Port/TrainPriceSource.php`
- Modify: `api/src/Domain/Pricing/TotalCost.php`
- Modify: `api/src/Domain/Pricing/TotalCostCalculator.php`
- Modify: `api/tests/Double/FixedTrainPriceSource.php`
- Modify: `api/tests/Domain/Pricing/TotalCostCalculatorTest.php`

**Interfaces:**
- Consumes: `TotalCost` (bestaand), `RoutePattern::ViaHelsinkiWithTrain`, `Money::zero()`
- Produces:
  - `TrainPriceSource::priceFor(\DateTimeImmutable): ?Money`
  - `TotalCost` krijgt veld `public bool $trainCostKnown`
  - `TotalCostCalculator::calculate(Itinerary, Traveller, ?Money): TotalCost` — bij `null` en ViaHelsinki: `trainCostKnown=false`, `indicative=true`

- [ ] **Stap 1: Schrijf de falende calculator-tests**

Voeg toe aan `api/tests/Domain/Pricing/TotalCostCalculatorTest.php` na de bestaande tests:

```php
public function test_null_train_price_for_helsinki_pattern_marks_cost_unknown_and_indicative(): void
{
    $cost = $this->calculator->calculate(
        self::viaHel(Carrier::Finnair, 134),
        Traveller::chris(),
        null,
    );

    self::assertFalse($cost->trainCostKnown, 'treinprijs null → trainCostKnown moet false zijn');
    self::assertTrue($cost->indicative, 'onbekende treinprijs → optie is indicatief');
    self::assertTrue($cost->trainCost->isZero(), 'rekent intern met nul zodat totaal klopt');
}

public function test_null_train_price_for_through_ticket_leaves_trainCostKnown_true(): void
{
    $cost = $this->calculator->calculate(
        self::throughTicket(Carrier::Sas, 150),
        Traveller::chris(),
        null,
    );

    self::assertTrue($cost->trainCostKnown, 'doorgaand ticket heeft geen trein, dus niets onbekends');
}

public function test_known_train_price_sets_trainCostKnown_true(): void
{
    $cost = $this->calculator->calculate(
        self::viaHel(Carrier::Finnair, 134),
        Traveller::chris(),
        Money::fromEuros(22.50),
    );

    self::assertTrue($cost->trainCostKnown);
}
```

- [ ] **Stap 2: Run tests om te bevestigen dat ze falen**

```bash
docker compose exec -T php php bin/phpunit tests/Domain/Pricing/TotalCostCalculatorTest.php
```

Verwacht: nieuwe tests falen (property `trainCostKnown` bestaat nog niet).

- [ ] **Stap 3: Pas `TrainPriceSource` interface aan**

Verander `api/src/Domain/Port/TrainPriceSource.php`:
```php
<?php

declare(strict_types=1);

namespace App\Domain\Port;

use App\Domain\Flight\Money;

interface TrainPriceSource
{
    /**
     * Goedkoopste VR-prijs Helsinki ⇄ Turku op deze datum.
     * Geeft null als er geen bruikbare prijs beschikbaar is
     * (lege scraperuitkomst, quota overschreden, etc.).
     */
    public function priceFor(\DateTimeImmutable $date): ?Money;
}
```

- [ ] **Stap 4: Pas `FixedTrainPriceSource` testdubbel aan**

`FixedTrainPriceSource` geeft nu altijd een `Money` terug. We voegen een fabrieksmethode toe voor het null-geval, zodat bestaande aanroepen blijven werken.

Vervang `api/tests/Double/FixedTrainPriceSource.php` volledig met:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Double;

use App\Domain\Flight\Money;
use App\Domain\Port\TrainPriceSource;

final class FixedTrainPriceSource implements TrainPriceSource
{
    public int $callCount = 0;

    private function __construct(private readonly ?Money $price)
    {
    }

    public static function withPrice(Money $price): self
    {
        return new self($price);
    }

    public static function withUnknownPrice(): self
    {
        return new self(null);
    }

    public function priceFor(\DateTimeImmutable $date): ?Money
    {
        ++$this->callCount;

        return $this->price;
    }
}
```

- [ ] **Stap 5: Pas `TotalCost` aan om `trainCostKnown` te bevatten**

Let op de volgorde in de constructor: `trainCostKnown` komt ná `indicative` zodat bestaande aanroepen met vier positionele argumenten niet stilzwijgend verkeerd gaan. We voegen het als vijfde argument toe met default `true`.

Vervang `api/src/Domain/Pricing/TotalCost.php` volledig:
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
        public bool $trainCostKnown = true,
    ) {
    }

    public function total(): Money
    {
        return $this->basePrice->add($this->baggageCost)->add($this->trainCost);
    }
}
```

- [ ] **Stap 6: Pas `TotalCostCalculator` aan**

Vervang `api/src/Domain/Pricing/TotalCostCalculator.php` volledig:
```php
<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

use App\Domain\Flight\Itinerary;
use App\Domain\Flight\Money;
use App\Domain\Flight\RoutePattern;
use App\Domain\Traveller\Traveller;

final readonly class TotalCostCalculator
{
    public function __construct(
        private BaggagePolicy $baggage,
        private FareUpgradeRates $rates,
    ) {
    }

    public function calculate(Itinerary $itinerary, Traveller $traveller, ?Money $trainCost): TotalCost
    {
        $baggageCost = $this->baggage->costFor(
            $itinerary->carrier,
            $traveller->statusFor($itinerary->carrier),
        );

        $isHelRoute = RoutePattern::ViaHelsinkiWithTrain === $itinerary->pattern;
        $trainCostKnown = true;

        if ($isHelRoute) {
            $train = $trainCost ?? Money::zero();
            $trainCostKnown = null !== $trainCost;
        } else {
            $train = Money::zero();
        }

        // Indicatief als: de bagagekosten geschat zijn, OF de treinprijs onbekend is.
        $indicative = (!$baggageCost->isZero() && $this->rates->isEstimate($itinerary->carrier))
            || ($isHelRoute && !$trainCostKnown);

        return new TotalCost($itinerary->basePrice, $baggageCost, $train, $indicative, $trainCostKnown);
    }
}
```

- [ ] **Stap 7: Pas bestaande aanroepen van `FixedTrainPriceSource` aan**

`FixedTrainPriceSource` heeft nu een private constructor. Bestaande aanroep in `SearchControllerTest.php`:
```php
new FixedTrainPriceSource(Money::fromEuros(22.50))
```
moet worden:
```php
FixedTrainPriceSource::withPrice(Money::fromEuros(22.50))
```

Zoek alle aanroepen:
```bash
grep -rn "new FixedTrainPriceSource" /Storage/Workspace/Projecten/flights/api/tests/
```

Pas elk gevonden geval aan van `new FixedTrainPriceSource(Money::...)` naar `FixedTrainPriceSource::withPrice(Money::...)`.

- [ ] **Stap 8: Run calculator-tests**

```bash
docker compose exec -T php php bin/phpunit tests/Domain/Pricing/TotalCostCalculatorTest.php
```

Verwacht: alle calculator-tests groen, inclusief de drie nieuwe.

- [ ] **Stap 9: Run alle PHP-tests**

```bash
docker compose exec -T php php bin/phpunit
```

Verwacht: alle tests groen.

- [ ] **Stap 10: Commit**

```bash
git add \
  api/src/Domain/Port/TrainPriceSource.php \
  api/src/Domain/Pricing/TotalCost.php \
  api/src/Domain/Pricing/TotalCostCalculator.php \
  api/tests/Double/FixedTrainPriceSource.php \
  api/tests/Domain/Pricing/TotalCostCalculatorTest.php \
  api/tests/Infrastructure/Http/SearchControllerTest.php
git commit -m "feat(domain): treinprijs null = onbekend; TotalCost krijgt trainCostKnown-vlag

Een null-trainCost voor de Helsinki-route zette eerder een onzichtbare €0
in het totaal. Nu markeert de calculator trainCostKnown=false en
indicative=true, zodat de UI kan tonen dat de prijs niet definitief is."
```

---

## Task 4: Wijziging 1 deel B — infrastructuur en presenter + frontend

**Files:**
- Modify: `api/src/Infrastructure/Source/ApifyTrainPriceSource.php` (null teruggeven)
- Modify: `api/src/Infrastructure/Http/SearchResultPresenter.php`
- Modify: `api/tests/Infrastructure/Source/ApifyTrainPriceSourceTest.php`
- Modify: `api/tests/Infrastructure/Http/SearchControllerTest.php`
- Modify: `web/src/types.ts`
- Modify: `web/src/components/OptionCard.vue`
- Modify: `web/src/components/__tests__/OptionCard.spec.ts`

**Interfaces:**
- Consumes: `TotalCost::$trainCostKnown` (uit Task 3), `TrainPriceSource::priceFor(): ?Money`
- Produces:
  - `ApifyTrainPriceSource::priceFor()` geeft `?Money`; leeg resultaat → `null`
  - JSON-veld `cost.trainCostKnown: bool`
  - Vue-component toont "onbekend" als `trainCostKnown === false`

- [ ] **Stap 1: Schrijf de falende Apify-test**

Voeg toe aan `api/tests/Infrastructure/Source/ApifyTrainPriceSourceTest.php`, vervang de bestaande `test_an_empty_result_yields_zero_rather_than_crashing`:

```php
public function test_an_empty_result_yields_null(): void
{
    $source = new ApifyTrainPriceSource(
        new MockHttpClient(new MockResponse('[]')),
        new ArrayAdapter(),
        'test-token',
    );

    self::assertNull($source->priceFor(new \DateTimeImmutable('2026-10-29')));
}
```

Verwijder ook de bestaande `test_an_empty_result_is_not_cached`-test want die veronderstelt `Money`-return; we schrijven hem opnieuw:

```php
public function test_a_null_result_is_cached_for_15_minutes(): void
{
    $calls = 0;
    $client = new MockHttpClient(function () use (&$calls) {
        ++$calls;

        return new MockResponse('[]');
    });

    $source = new ApifyTrainPriceSource($client, new ArrayAdapter(), 'test-token');

    $first  = $source->priceFor(new \DateTimeImmutable('2026-10-29'));
    $second = $source->priceFor(new \DateTimeImmutable('2026-10-29'));

    self::assertNull($first);
    self::assertNull($second);
    self::assertSame(1, $calls, 'null-uitkomst moet worden gecacht (15 min) zodat niet elke klik een Apify-run kost');
}
```

- [ ] **Stap 2: Schrijf de falende controller/presenter-test**

Voeg toe aan `api/tests/Infrastructure/Http/SearchControllerTest.php`:

```php
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
```

- [ ] **Stap 3: Run falende tests om te verifiëren**

```bash
docker compose exec -T php php bin/phpunit tests/Infrastructure/Source/ApifyTrainPriceSourceTest.php tests/Infrastructure/Http/SearchControllerTest.php
```

Verwacht: nieuwe tests falen.

- [ ] **Stap 4: Pas `ApifyTrainPriceSource` aan**

Vervang `api/src/Infrastructure/Source/ApifyTrainPriceSource.php` volledig:

```php
<?php

declare(strict_types=1);

namespace App\Infrastructure\Source;

use App\Domain\Flight\Money;
use App\Domain\Port\TrainPriceSource;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class ApifyTrainPriceSource implements TrainPriceSource
{
    private const ACTOR = 'studio-amba~vr-scraper';
    private const TTL_SECONDS = 86400;
    private const TTL_NULL_SECONDS = 900; // 15 minuten: mislukken cachen om Apify-tegoed te sparen

    /**
     * Bovengrens voor een treinkaartje HEL–TKU in euro.
     * Een VR-kaartje kost enkele tientjes; alles daarboven is een scraperartefact.
     * Zonder deze grens loopt (int) round($euros * 100) over naar een negatief
     * getal, waarop Money een InvalidArgumentException gooit — HTTP 500.
     */
    private const MAX_TRAIN_PRICE_EUROS = 10_000.0;

    /**
     * Schildwacht-waarde die in de cache staat als de scraper niets bruikbaars leverde.
     * Onderscheidt "null gecacht" van "prijs is 0 cent" en van "cache miss".
     */
    private const CACHE_NULL_SENTINEL = 'null';

    public function __construct(
        private HttpClientInterface $http,
        private CacheItemPoolInterface $cache,
        private string $apiToken,
    ) {
    }

    public function priceFor(\DateTimeImmutable $date): ?Money
    {
        $item = $this->cache->getItem('vr.hel-tku.'.$date->format('Y-m-d'));

        if ($item->isHit()) {
            $cached = $item->get();

            if (self::CACHE_NULL_SENTINEL === $cached) {
                return null;
            }

            return Money::fromCents((int) $cached);
        }

        $price = $this->fetch($date);

        if (null === $price) {
            $item->set(self::CACHE_NULL_SENTINEL);
            $item->expiresAfter(self::TTL_NULL_SECONDS);
        } else {
            $item->set($price->cents);
            $item->expiresAfter(self::TTL_SECONDS);
        }

        $this->cache->save($item);

        return $price;
    }

    private function fetch(\DateTimeImmutable $date): ?Money
    {
        $response = $this->http->request('POST', sprintf(
            'https://api.apify.com/v2/acts/%s/run-sync-get-dataset-items?token=%s',
            self::ACTOR,
            urlencode($this->apiToken),
        ), [
            'json' => [
                'from' => 'Helsinki',
                'to' => 'Turku',
                'date' => $date->format('Y-m-d'),
            ],
        ]);

        $rows = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        $prices = array_filter(array_map(
            static function (array $row): ?float {
                $raw = $row['priceEur'] ?? null;

                // Filter onbruikbare prijzen vóór de float-cast:
                // - niet-numeriek (N/A, lege string, gewone strings) → null
                // - absurd hoog (incl. INF, want INF > MAX_TRAIN_PRICE_EUROS is waar) → null
                // - negatief → null (een negatieve treinprijs is geen bruikbare prijs; -INF valt hier ook onder)
                // Prijs 0.0 is geldig: een gratis rit is mogelijk.
                if (!is_numeric($raw)) {
                    return null;
                }

                $price = (float) $raw;

                // De bovengrens vangt tevens INF af (INF > MAX_TRAIN_PRICE_EUROS is waar),
                // waardoor een aparte is_finite()-toets overbodig is. -INF valt onder < 0.0.
                if ($price < 0.0 || $price > self::MAX_TRAIN_PRICE_EUROS) {
                    return null;
                }

                return $price;
            },
            is_array($rows) ? $rows : [],
        ), static fn (?float $p): bool => null !== $p);

        if ([] === $prices) {
            return null;
        }

        return Money::fromEuros(min($prices));
    }
}
```

- [ ] **Stap 5: Pas `SearchResultPresenter` aan**

In `api/src/Infrastructure/Http/SearchResultPresenter.php`, voeg `trainCostKnown` toe aan het `cost`-blok:

```php
'cost' => [
    'basePrice' => $cost->basePrice->cents,
    'baggageCost' => $cost->baggageCost->cents,
    'trainCost' => $cost->trainCost->cents,
    'total' => $cost->total()->cents,
    'totalFormatted' => $cost->total()->format(),
    'indicative' => $cost->indicative,
    'trainCostKnown' => $cost->trainCostKnown,
],
```

- [ ] **Stap 6: Run PHP-tests**

```bash
docker compose exec -T php php bin/phpunit
```

Verwacht: alle tests groen (de bestaande `test_an_empty_result_yields_zero_rather_than_crashing` en `test_an_empty_result_is_not_cached` zijn vervangen door nieuwe tests; controleer dat het aantal klopt met de beschrijving van elke test die je verwijderde vs. toevoegde).

- [ ] **Stap 7: Mutatietest — bevestig dat de test scherp is**

Maak tijdelijk `ApifyTrainPriceSource::fetch()` weer `Money::zero()` teruggeven i.p.v. `null`:

```php
if ([] === $prices) {
    return Money::zero();  // tijdelijke mutatie
}
```

Run de test die dit moet opvangen:

```bash
docker compose exec -T php php bin/phpunit tests/Infrastructure/Source/ApifyTrainPriceSourceTest.php --filter test_an_empty_result_yields_null
```

Verwacht: FAIL — de test detecteert de mutatie.

Zet terug naar `return null;` en run opnieuw:

```bash
docker compose exec -T php php bin/phpunit tests/Infrastructure/Source/ApifyTrainPriceSourceTest.php --filter test_an_empty_result_yields_null
```

Verwacht: PASS.

- [ ] **Stap 8: Pas `web/src/types.ts` aan**

Voeg `trainCostKnown: boolean` toe aan de `Cost` interface:

```typescript
export interface Cost {
  basePrice: number
  baggageCost: number
  trainCost: number
  total: number
  totalFormatted: string
  indicative: boolean
  trainCostKnown: boolean
}
```

- [ ] **Stap 9: Schrijf de falende Vue-test**

Voeg toe aan `web/src/components/__tests__/OptionCard.spec.ts`:

```typescript
it('toont "onbekend" in de treinregel als trainCostKnown false is', () => {
  const unknownTrain: Option = {
    ...viaHelsinki,
    cost: {
      ...viaHelsinki.cost,
      trainCostKnown: false,
      indicative: true,
    },
  }

  const card = mount(OptionCard, { props: { option: unknownTrain } })
  const rows = card.findAll('[data-testid="cost-row"]')
  const trainRow = rows.find(r => r.text().includes('Trein'))

  expect(trainRow).toBeDefined()
  expect(trainRow!.text()).toContain('onbekend')
  expect(trainRow!.text()).not.toContain('€ 0,00')
  expect(card.find('[data-testid="indicative"]').exists()).toBe(true)
})
```

Pas ook de bestaande `viaHelsinki`-fixture aan zodat hij het nieuwe veld bevat:

```typescript
cost: {
  basePrice: 13400,
  baggageCost: 0,
  trainCost: 2250,
  total: 15650,
  totalFormatted: '€ 156,50',
  indicative: false,
  trainCostKnown: true,  // nieuw veld
},
```

- [ ] **Stap 10: Run frontend-tests om te bevestigen dat de nieuwe test faalt**

```bash
docker compose run --rm node npm run test
```

Verwacht: nieuwe test faalt (TypeScript-compilatiefout óf runtime-fout omdat de Vue-component `trainCostKnown` nog niet kent).

- [ ] **Stap 11: Pas `OptionCard.vue` aan**

De treinregel moet "onbekend" tonen als `trainCostKnown` false is. We passen de `rows`-berekening en de template aan.

Vervang `web/src/components/OptionCard.vue` volledig:

```vue
<script setup lang="ts">
import { computed } from 'vue'
import type { Option } from '../types'

const props = defineProps<{ option: Option }>()

const euro = (cents: number) => `€ ${(cents / 100).toFixed(2).replace('.', ',')}`

const time = (iso: string) =>
  new Date(iso).toLocaleTimeString('nl-NL', {
    hour: '2-digit',
    minute: '2-digit',
    timeZone: 'UTC',
  })

interface CostRow {
  label: string
  cents: number
  unknown?: boolean
}

const rows = computed(() => {
  const r: CostRow[] = [
    { label: 'Vlucht', cents: props.option.cost.basePrice },
    { label: 'Bagage', cents: props.option.cost.baggageCost },
  ]

  if (props.option.pattern === 'via_hel_train') {
    r.push({
      label: 'Trein HEL–TKU',
      cents: props.option.cost.trainCost,
      unknown: !props.option.cost.trainCostKnown,
    })
  }

  return r
})
</script>

<template>
  <article class="card">
    <header>
      <strong>{{ option.carrierLabel }}</strong>
      <span>{{ time(option.departAt) }} → {{ time(option.arriveAt) }}</span>
      <span v-if="option.stops > 0">
        via {{ option.via }} ({{ option.connectionMinutes }} min)
      </span>
      <span v-else>direct</span>
    </header>

    <p class="total">
      {{ option.cost.totalFormatted }}
      <em v-if="option.cost.indicative" data-testid="indicative">indicatief</em>
    </p>

    <dl>
      <div v-for="row in rows" :key="row.label" data-testid="cost-row">
        <dt>{{ row.label }}</dt>
        <dd v-if="row.unknown">onbekend</dd>
        <dd v-else>{{ euro(row.cents) }}</dd>
      </div>
    </dl>

    <footer>{{ option.flightNumbers.join(' · ') }}</footer>
  </article>
</template>

<style scoped>
.card { border: 1px solid #ddd; border-radius: 8px; padding: 1rem; margin-bottom: .75rem; }
header { display: flex; gap: .75rem; flex-wrap: wrap; color: #555; }
.total { font-size: 1.5rem; margin: .5rem 0; }
.total em { font-size: .8rem; color: #a15c00; font-style: normal; }
dl div { display: flex; justify-content: space-between; border-top: 1px solid #f0f0f0; padding: .2rem 0; }
dt, dd { margin: 0; }
footer { margin-top: .5rem; color: #888; font-size: .85rem; }
</style>
```

- [ ] **Stap 12: Run alle frontend-tests**

```bash
docker compose run --rm node npm run test
```

Verwacht: alle 9 tests groen (8 bestaand + 1 nieuw).

- [ ] **Stap 13: Build frontend om TypeScript te controleren**

```bash
docker compose run --rm node npm run build
```

Verwacht: geen TypeScript-fouten, build succesvol.

- [ ] **Stap 14: Commit**

```bash
git add \
  api/src/Infrastructure/Source/ApifyTrainPriceSource.php \
  api/src/Infrastructure/Http/SearchResultPresenter.php \
  api/tests/Infrastructure/Source/ApifyTrainPriceSourceTest.php \
  api/tests/Infrastructure/Http/SearchControllerTest.php \
  web/src/types.ts \
  web/src/components/OptionCard.vue \
  web/src/components/__tests__/OptionCard.spec.ts
git commit -m "feat: treinprijs onbekend propageren van bron tot UI

ApifyTrainPriceSource geeft null terug als de scraper niets bruikbaars
levert — niet Money::zero(). Die null loopt via trainCostKnown=false
en indicative=true door naar de presenter en de OptionCard, die
'onbekend' toont in plaats van €0,00. Een onzichtbare gratis trein
leek altijd te winnen in de vergelijking; dat is nu onmogelijk."
```

---

## Task 5: Wijziging 2 — cache ook het mislukken (15 minuten)

Deze wijziging is al ingebakken in Task 4 (stap 4 van de Apify-aanpassing). Toch voegen we hier een expliciete cachingtest toe die twee opeenvolgende zoekopdrachten bij een kapotte bron controleert op exact één Apify-aanroep.

**Files:**
- Modify: `api/tests/Infrastructure/Source/ApifyTrainPriceSourceTest.php`

**Interfaces:**
- Consumes: `ApifyTrainPriceSource` (uit Task 4, al geschreven)
- Produces: test die bewijst dat null-resultaten worden gecacht

- [ ] **Stap 1: Controleer of `test_a_null_result_is_cached_for_15_minutes` al aanwezig is**

```bash
grep -n "test_a_null_result_is_cached_for_15_minutes" /Storage/Workspace/Projecten/flights/api/tests/Infrastructure/Source/ApifyTrainPriceSourceTest.php
```

Als de test al aanwezig is (toegevoegd in Task 4 stap 1), sla dan stap 2 over.

- [ ] **Stap 2: Voeg test toe als hij nog ontbreekt**

Voeg toe aan `api/tests/Infrastructure/Source/ApifyTrainPriceSourceTest.php`:

```php
public function test_a_null_result_is_cached_for_15_minutes(): void
{
    $calls = 0;
    $client = new MockHttpClient(function () use (&$calls) {
        ++$calls;

        return new MockResponse('[]');
    });

    $source = new ApifyTrainPriceSource($client, new ArrayAdapter(), 'test-token');

    $first  = $source->priceFor(new \DateTimeImmutable('2026-10-29'));
    $second = $source->priceFor(new \DateTimeImmutable('2026-10-29'));

    self::assertNull($first);
    self::assertNull($second);
    self::assertSame(1, $calls, 'null-uitkomst moet worden gecacht (15 min) zodat niet elke klik een Apify-run kost');
}
```

- [ ] **Stap 3: Run alle PHP-tests**

```bash
docker compose exec -T php php bin/phpunit
```

Verwacht: alle tests groen.

- [ ] **Stap 4: Noteer testuitslag en commit (alleen als test nieuw was)**

Als stap 2 iets veranderde:

```bash
git add api/tests/Infrastructure/Source/ApifyTrainPriceSourceTest.php
git commit -m "test(infra): bevestig dat null-treinprijsresultaat 15 min gecacht wordt

Twee opeenvolgende zoekopdrachten bij een kapotte scraper mogen niet
elk een Apify-run starten. Het gratis tegoed is ~20-50 runs per maand."
```

---

## Eindcontrole

Na alle taken:

- [ ] **Volledige PHP-testsuite**

```bash
docker compose exec -T php php bin/phpunit
```

Verwacht: minimaal 109 tests groen (103 origineel + 3 Money + 3 Calculator + 2 Controller + 1 Apify-null-gecacht + 1 Apify-empty-yields-null = ~113, afhankelijk van overlap).

- [ ] **Volledige frontend-testsuite**

```bash
docker compose run --rm node npm run test
```

Verwacht: minimaal 9 tests groen.

- [ ] **Frontend build**

```bash
docker compose run --rm node npm run build
```

Verwacht: geen fouten.

- [ ] **Schrijf verslag**

Schrijf verslag naar `/Storage/Workspace/Projecten/flights/.superpowers/sdd/treinprijs-onbekend-report.md` met per wijziging: wat gedaan, commando's + uitvoer, mutatietestresultaat.
