# Vluchtzoeker fase 1 — implementatieplan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Zoek voor één datum de goedkoopste reisopties Leiden ↔ Turku, met een totaalprijs die de VR-trein en de bagagekosten per reiziger meerekent, getoond in een Vue-dashboard.

**Architecture:** Symfony 7 met een framework-vrije domeinlaag (`src/Domain`) waarin alle regel-, bagage- en prijslogica zit zonder I/O. Externe prijsbronnen zitten achter `Port`-interfaces met adapters in `src/Infrastructure`. Een losse Vue 3 SPA praat via JSON met de API. Alles draait in Docker Compose.

**Tech Stack:** PHP 8.4, Symfony 7.2, PHPUnit 11, Vue 3 + Vite + TypeScript, nginx, Docker Compose. Geen database in fase 1.

**Spec:** [`../specs/2026-09-16-leiden-turku-vluchtzoeker-design.md`](../specs/2026-09-16-leiden-turku-vluchtzoeker-design.md)

## Global Constraints

- PHP **8.4**, Symfony **7.4 LTS** (`^7.2` loste bij installatie op naar 7.4.19; LTS is beter en API-compatibel). Alle PHP-bestanden beginnen met `declare(strict_types=1);`.
- De PHPUnit-configuratie heet `api/phpunit.dist.xml` (zo legt de Symfony-recipe hem neer), niet `phpunit.xml.dist`.
- `src/Domain/**` mag **geen** `use Symfony\`, `use Doctrine\` of andere framework-imports bevatten. Dit wordt in Taak 15 automatisch afgedwongen.
- Alle geldbedragen zijn `Money` in hele centen (`int`). Nooit `float` voor geld.
- Maatschappijen: alleen `AY` (Finnair), `SK` (SAS), `KL` (KLM).
- Luchthavens: `AMS`, `HEL`, `TKU`, `ARN`, `CPH`.
- Overstapregels: precies één overstap, ≤ **120 minuten**, alleen via `ARN` of `CPH` (doorgaand) of `HEL` (patroon via-trein).
- Tijdvensters: aankomst `HEL` uiterlijk **21:00**, vertrek `HEL` niet vóór **12:00**. Alleen van toepassing op patroon via-Helsinki.
- Reizigers: `chris` (SAS Silver, Finnair Silver) en `partner` (SAS Silver, Finnair geen status).
- `fare_upgrade`-tarieven zijn verplicht geconfigureerd. Een ontbrekende waarde **moet** een exception gooien bij het opstarten — nooit stilzwijgend €0.
- Tests raken **nooit** het netwerk. Adapters worden getest tegen opgenomen fixtures.
- Commit na elke taak.

## Bestandsindeling

| Bestand | Verantwoordelijkheid |
|---|---|
| `api/src/Domain/Flight/Money.php` | Geldbedrag in centen, optellen/vergelijken |
| `api/src/Domain/Flight/Carrier.php` | Enum AY/SK/KL |
| `api/src/Domain/Flight/Airport.php` | Enum AMS/HEL/TKU/ARN/CPH |
| `api/src/Domain/Flight/Direction.php` | Enum NlToFi/FiToNl |
| `api/src/Domain/Flight/RoutePattern.php` | Enum ViaHelsinkiWithTrain/ThroughTicket |
| `api/src/Domain/Flight/Segment.php` | Eén vliegtuigbeweging |
| `api/src/Domain/Flight/Itinerary.php` | Reisoptie: segmenten + basisprijs + patroon |
| `api/src/Domain/Traveller/LoyaltyStatus.php` | Enum None/Silver |
| `api/src/Domain/Traveller/Traveller.php` | Reiziger met status per maatschappij |
| `api/src/Domain/Pricing/FareUpgradeRates.php` | Light→Plus-tarieven, faalt bij ontbreken |
| `api/src/Domain/Pricing/BaggageEntitlement.php` | Wat is gratis inbegrepen |
| `api/src/Domain/Pricing/BaggagePolicy.php` | Bagagekosten per maatschappij × status |
| `api/src/Domain/Pricing/TotalCost.php` | Prijsopbouw + indicatief-vlag |
| `api/src/Domain/Pricing/TotalCostCalculator.php` | Stelt TotalCost samen |
| `api/src/Domain/Rules/ItineraryRule.php` | Interface voor één regel |
| `api/src/Domain/Rules/*.php` | De losse regels |
| `api/src/Domain/Rules/RuleSet.php` | Bundelt en filtert |
| `api/src/Domain/Trip/PlannedTrip.php` | Eén geplande reis |
| `api/src/Domain/Trip/PlannedTrips.php` | De planning; fase 4 vervangt dit door Calendar |
| `api/src/Domain/Port/*.php` | FlightPriceSource, TrainPriceSource, PriceHistory |
| `api/src/Application/SearchFlights/*.php` | Query, Handler, SearchResult, PricedItinerary |
| `api/src/Infrastructure/Source/BrightDataFlightSource.php` | Google Flights via Bright Data |
| `api/src/Infrastructure/Source/ApifyTrainPriceSource.php` | VR-prijs via Apify, gecached |
| `api/src/Infrastructure/Persistence/NullPriceHistory.php` | No-op; fase 2 vervangt dit |
| `api/src/Infrastructure/Http/SearchController.php` | GET /api/search |
| `api/src/Infrastructure/Http/TripsController.php` | GET /api/trips |
| `web/src/App.vue` | Het enige scherm |
| `web/src/components/OptionCard.vue` | Eén reisoptie met prijsopbouw |

---

### Task 1: Docker-omgeving en Symfony-skelet

**Files:**
- Create: `compose.yaml`, `docker/php/Dockerfile`, `docker/nginx/default.conf`, `.gitignore`
- Create: `api/` (via composer), `api/phpunit.xml.dist`
- Test: `api/tests/SmokeTest.php`

**Interfaces:**
- Consumes: niets
- Produces: draaiende `php`-container met Symfony 7.2 in `/app`, PHPUnit via `docker compose exec php bin/phpunit`

- [ ] **Step 1: Maak `.gitignore`**

```gitignore
api/vendor/
api/var/
api/.env.local
web/node_modules/
web/dist/
.env
```

- [ ] **Step 2: Maak `docker/php/Dockerfile`**

```dockerfile
FROM php:8.4-fpm-alpine

RUN apk add --no-cache git unzip icu-dev linux-headers \
 && docker-php-ext-install intl opcache \
 && apk del linux-headers

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
```

- [ ] **Step 3: Maak `docker/nginx/default.conf`**

```nginx
server {
    listen 80;
    root /app/public;

    location /api {
        try_files $uri /index.php$is_args$args;
    }

    location ~ ^/index\.php(/|$) {
        fastcgi_pass php:9000;
        fastcgi_split_path_info ^(.+\.php)(/.*)$;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_param DOCUMENT_ROOT $document_root;
        internal;
    }

    location / {
        proxy_pass http://node:5173;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_set_header Host $host;
    }
}
```

- [ ] **Step 4: Maak `compose.yaml`**

```yaml
services:
  php:
    build: ./docker/php
    volumes:
      - ./api:/app
    working_dir: /app

  nginx:
    image: nginx:alpine
    ports:
      - "8080:80"
    volumes:
      - ./api:/app
      - ./docker/nginx/default.conf:/etc/nginx/conf.d/default.conf:ro
    depends_on:
      - php
      - node

  node:
    image: node:22-alpine
    volumes:
      - ./web:/app
    working_dir: /app
    command: sh -c "npm install && npm run dev -- --host 0.0.0.0"
```

- [ ] **Step 5: Bouw de php-container en installeer Symfony**

```bash
docker compose build php
docker compose run --rm php composer create-project symfony/skeleton:^7.2 /tmp/sf --no-interaction
docker compose run --rm php sh -c "cp -a /tmp/sf/. /app/ && rm -rf /tmp/sf"
docker compose run --rm php composer require --dev phpunit/phpunit:^11 symfony/test-pack --no-interaction
```

Expected: `api/composer.json` bestaat, `api/vendor/` gevuld.

- [ ] **Step 6: Zet de autoload-namespaces goed**

Wijzig in `api/composer.json` het `autoload`-blok naar:

```json
"autoload": {
    "psr-4": {
        "App\\": "src/"
    }
},
"autoload-dev": {
    "psr-4": {
        "App\\Tests\\": "tests/"
    }
}
```

Daarna: `docker compose run --rm php composer dump-autoload`

- [ ] **Step 7: Schrijf de falende rooktest**

Maak `api/tests/SmokeTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests;

use PHPUnit\Framework\TestCase;

final class SmokeTest extends TestCase
{
    public function test_the_test_runner_works(): void
    {
        self::assertSame('8.4', substr(PHP_VERSION, 0, 3));
    }
}
```

- [ ] **Step 8: Draai de test**

Run: `docker compose run --rm php bin/phpunit tests/SmokeTest.php`
Expected: PASS, 1 test, 1 assertion.

- [ ] **Step 9: Commit**

```bash
git add -A
git commit -m "chore: docker-omgeving en symfony-skelet"
```

---

### Task 2: Money, Carrier, Airport, Direction, RoutePattern

**Files:**
- Create: `api/src/Domain/Flight/Money.php`, `Carrier.php`, `Airport.php`, `Direction.php`, `RoutePattern.php`
- Test: `api/tests/Domain/Flight/MoneyTest.php`

**Interfaces:**
- Consumes: niets
- Produces:
  - `Money::fromEuros(float): self`, `Money::fromCents(int): self`, `Money::zero(): self`
  - `Money->cents: int`, `->add(Money): self`, `->isLessThan(Money): bool`, `->isZero(): bool`, `->format(): string`
  - `Carrier` enum: `Carrier::Finnair` (`'AY'`), `::Sas` (`'SK'`), `::Klm` (`'KL'`)
  - `Airport` enum: `::Ams`, `::Hel`, `::Tku`, `::Arn`, `::Cph` (waarden `'AMS'` enz.)
  - `Direction` enum: `::NlToFi` (`'nl-fi'`), `::FiToNl` (`'fi-nl'`)
  - `RoutePattern` enum: `::ViaHelsinkiWithTrain`, `::ThroughTicket`

- [ ] **Step 1: Schrijf de falende test**

Maak `api/tests/Domain/Flight/MoneyTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Domain\Flight;

use App\Domain\Flight\Money;
use PHPUnit\Framework\TestCase;

final class MoneyTest extends TestCase
{
    public function test_euros_become_whole_cents(): void
    {
        self::assertSame(13400, Money::fromEuros(134.00)->cents);
        self::assertSame(3550, Money::fromEuros(35.50)->cents);
    }

    public function test_rounding_does_not_drift(): void
    {
        self::assertSame(1010, Money::fromEuros(10.1)->cents);
    }

    public function test_addition(): void
    {
        $sum = Money::fromEuros(134.00)->add(Money::fromEuros(12.50));

        self::assertSame(14650, $sum->cents);
    }

    public function test_comparison(): void
    {
        self::assertTrue(Money::fromEuros(80)->isLessThan(Money::fromEuros(134)));
        self::assertFalse(Money::fromEuros(134)->isLessThan(Money::fromEuros(80)));
    }

    public function test_zero(): void
    {
        self::assertTrue(Money::zero()->isZero());
        self::assertFalse(Money::fromEuros(1)->isZero());
    }

    public function test_dutch_formatting(): void
    {
        self::assertSame('€ 134,00', Money::fromEuros(134)->format());
        self::assertSame('€ 9,05', Money::fromEuros(9.05)->format());
    }

    public function test_negative_amounts_are_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Money::fromCents(-1);
    }
}
```

- [ ] **Step 2: Draai de test en zie hem falen**

Run: `docker compose run --rm php bin/phpunit tests/Domain/Flight/MoneyTest.php`
Expected: FAIL — `Class "App\Domain\Flight\Money" not found`.

- [ ] **Step 3: Implementeer Money**

Maak `api/src/Domain/Flight/Money.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domain\Flight;

final readonly class Money
{
    private function __construct(public int $cents)
    {
        if ($cents < 0) {
            throw new \InvalidArgumentException('Bedrag mag niet negatief zijn.');
        }
    }

    public static function fromCents(int $cents): self
    {
        return new self($cents);
    }

    public static function fromEuros(float $euros): self
    {
        return new self((int) round($euros * 100));
    }

    public static function zero(): self
    {
        return new self(0);
    }

    public function add(self $other): self
    {
        return new self($this->cents + $other->cents);
    }

    public function isLessThan(self $other): bool
    {
        return $this->cents < $other->cents;
    }

    public function isZero(): bool
    {
        return 0 === $this->cents;
    }

    public function format(): string
    {
        return sprintf('€ %s,%02d', intdiv($this->cents, 100), $this->cents % 100);
    }
}
```

- [ ] **Step 4: Draai de test**

Run: `docker compose run --rm php bin/phpunit tests/Domain/Flight/MoneyTest.php`
Expected: PASS, 7 tests.

- [ ] **Step 5: Maak de enums**

`api/src/Domain/Flight/Carrier.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domain\Flight;

enum Carrier: string
{
    case Finnair = 'AY';
    case Sas = 'SK';
    case Klm = 'KL';

    public function label(): string
    {
        return match ($this) {
            self::Finnair => 'Finnair',
            self::Sas => 'SAS',
            self::Klm => 'KLM',
        };
    }
}
```

`api/src/Domain/Flight/Airport.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domain\Flight;

enum Airport: string
{
    case Ams = 'AMS';
    case Hel = 'HEL';
    case Tku = 'TKU';
    case Arn = 'ARN';
    case Cph = 'CPH';
}
```

`api/src/Domain/Flight/Direction.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domain\Flight;

enum Direction: string
{
    case NlToFi = 'nl-fi';
    case FiToNl = 'fi-nl';

    public function isOutbound(): bool
    {
        return self::NlToFi === $this;
    }
}
```

`api/src/Domain/Flight/RoutePattern.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domain\Flight;

enum RoutePattern: string
{
    case ViaHelsinkiWithTrain = 'via_hel_train';
    case ThroughTicket = 'through';
}
```

- [ ] **Step 6: Draai de hele suite**

Run: `docker compose run --rm php bin/phpunit`
Expected: PASS, 8 tests.

- [ ] **Step 7: Commit**

```bash
git add api/src/Domain/Flight api/tests/Domain/Flight
git commit -m "feat: money value object en domein-enums"
```

---

### Task 3: Traveller en LoyaltyStatus

**Files:**
- Create: `api/src/Domain/Traveller/LoyaltyStatus.php`, `api/src/Domain/Traveller/Traveller.php`
- Test: `api/tests/Domain/Traveller/TravellerTest.php`

**Interfaces:**
- Consumes: `Carrier` (Taak 2)
- Produces:
  - `LoyaltyStatus` enum: `::None` (`'none'`), `::Silver` (`'silver'`)
  - `Traveller::chris(): self`, `Traveller::partner(): self`, `Traveller::byId(string): self`
  - `Traveller->id: string`, `->name: string`, `->statusFor(Carrier): LoyaltyStatus`

- [ ] **Step 1: Schrijf de falende test**

Maak `api/tests/Domain/Traveller/TravellerTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Domain\Traveller;

use App\Domain\Flight\Carrier;
use App\Domain\Traveller\LoyaltyStatus;
use App\Domain\Traveller\Traveller;
use PHPUnit\Framework\TestCase;

final class TravellerTest extends TestCase
{
    public function test_chris_has_silver_at_both_finnair_and_sas(): void
    {
        $chris = Traveller::chris();

        self::assertSame(LoyaltyStatus::Silver, $chris->statusFor(Carrier::Finnair));
        self::assertSame(LoyaltyStatus::Silver, $chris->statusFor(Carrier::Sas));
    }

    public function test_partner_has_silver_at_sas_but_nothing_at_finnair(): void
    {
        $partner = Traveller::partner();

        self::assertSame(LoyaltyStatus::Silver, $partner->statusFor(Carrier::Sas));
        self::assertSame(LoyaltyStatus::None, $partner->statusFor(Carrier::Finnair));
    }

    public function test_nobody_has_status_at_klm(): void
    {
        self::assertSame(LoyaltyStatus::None, Traveller::chris()->statusFor(Carrier::Klm));
        self::assertSame(LoyaltyStatus::None, Traveller::partner()->statusFor(Carrier::Klm));
    }

    public function test_lookup_by_id(): void
    {
        self::assertSame('chris', Traveller::byId('chris')->id);
        self::assertSame('partner', Traveller::byId('partner')->id);
    }

    public function test_unknown_id_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Traveller::byId('iemand-anders');
    }
}
```

- [ ] **Step 2: Draai de test en zie hem falen**

Run: `docker compose run --rm php bin/phpunit tests/Domain/Traveller/TravellerTest.php`
Expected: FAIL — `Class "App\Domain\Traveller\Traveller" not found`.

- [ ] **Step 3: Implementeer LoyaltyStatus**

Maak `api/src/Domain/Traveller/LoyaltyStatus.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domain\Traveller;

enum LoyaltyStatus: string
{
    case None = 'none';
    case Silver = 'silver';
}
```

- [ ] **Step 4: Implementeer Traveller**

Maak `api/src/Domain/Traveller/Traveller.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domain\Traveller;

use App\Domain\Flight\Carrier;

final readonly class Traveller
{
    /**
     * @param array<string, LoyaltyStatus> $loyalty gesleuteld op Carrier-waarde
     */
    private function __construct(
        public string $id,
        public string $name,
        private array $loyalty,
    ) {
    }

    public static function chris(): self
    {
        return new self('chris', 'Chris', [
            Carrier::Finnair->value => LoyaltyStatus::Silver,
            Carrier::Sas->value => LoyaltyStatus::Silver,
        ]);
    }

    public static function partner(): self
    {
        return new self('partner', 'Partner', [
            Carrier::Sas->value => LoyaltyStatus::Silver,
        ]);
    }

    public static function byId(string $id): self
    {
        return match ($id) {
            'chris' => self::chris(),
            'partner' => self::partner(),
            default => throw new \InvalidArgumentException(sprintf('Onbekende reiziger "%s".', $id)),
        };
    }

    public function statusFor(Carrier $carrier): LoyaltyStatus
    {
        return $this->loyalty[$carrier->value] ?? LoyaltyStatus::None;
    }
}
```

- [ ] **Step 5: Draai de test**

Run: `docker compose run --rm php bin/phpunit tests/Domain/Traveller/TravellerTest.php`
Expected: PASS, 5 tests.

- [ ] **Step 6: Commit**

```bash
git add api/src/Domain/Traveller api/tests/Domain/Traveller
git commit -m "feat: reizigers met statusprofiel per maatschappij"
```

---

### Task 4: Segment en Itinerary

**Files:**
- Create: `api/src/Domain/Flight/Segment.php`, `api/src/Domain/Flight/Itinerary.php`
- Test: `api/tests/Domain/Flight/ItineraryTest.php`

**Interfaces:**
- Consumes: `Airport`, `Carrier`, `Money`, `RoutePattern` (Taak 2)
- Produces:
  - `new Segment(Airport $from, Airport $to, \DateTimeImmutable $departAt, \DateTimeImmutable $arriveAt, string $flightNumber)`
  - `new Itinerary(Carrier $carrier, array $segments, Money $basePrice, RoutePattern $pattern)`
  - `Itinerary->origin(): Airport`, `->destination(): Airport`, `->departAt()`, `->arriveAt()`
  - `->stopCount(): int`, `->connectionMinutes(): ?int`, `->connectsVia(): ?Airport`
  - `->carrier: Carrier`, `->basePrice: Money`, `->pattern: RoutePattern`, `->segments: Segment[]`

- [ ] **Step 1: Schrijf de falende test**

Maak `api/tests/Domain/Flight/ItineraryTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Domain\Flight;

use App\Domain\Flight\Airport;
use App\Domain\Flight\Carrier;
use App\Domain\Flight\Itinerary;
use App\Domain\Flight\Money;
use App\Domain\Flight\RoutePattern;
use App\Domain\Flight\Segment;
use PHPUnit\Framework\TestCase;

final class ItineraryTest extends TestCase
{
    public function test_direct_flight_has_no_stops(): void
    {
        $it = new Itinerary(
            Carrier::Finnair,
            [self::segment(Airport::Ams, Airport::Hel, '2026-10-29 13:00', '2026-10-29 16:45', 'AY842')],
            Money::fromEuros(134),
            RoutePattern::ViaHelsinkiWithTrain,
        );

        self::assertSame(0, $it->stopCount());
        self::assertNull($it->connectionMinutes());
        self::assertNull($it->connectsVia());
        self::assertSame(Airport::Ams, $it->origin());
        self::assertSame(Airport::Hel, $it->destination());
    }

    public function test_one_stop_exposes_connection_details(): void
    {
        $it = new Itinerary(
            Carrier::Sas,
            [
                self::segment(Airport::Ams, Airport::Arn, '2026-10-29 09:00', '2026-10-29 11:00', 'SK552'),
                self::segment(Airport::Arn, Airport::Tku, '2026-10-29 12:30', '2026-10-29 14:10', 'SK721'),
            ],
            Money::fromEuros(150),
            RoutePattern::ThroughTicket,
        );

        self::assertSame(1, $it->stopCount());
        self::assertSame(90, $it->connectionMinutes());
        self::assertSame(Airport::Arn, $it->connectsVia());
        self::assertSame(Airport::Ams, $it->origin());
        self::assertSame(Airport::Tku, $it->destination());
    }

    public function test_departure_and_arrival_span_the_whole_journey(): void
    {
        $it = new Itinerary(
            Carrier::Sas,
            [
                self::segment(Airport::Ams, Airport::Arn, '2026-10-29 09:00', '2026-10-29 11:00', 'SK552'),
                self::segment(Airport::Arn, Airport::Tku, '2026-10-29 12:30', '2026-10-29 14:10', 'SK721'),
            ],
            Money::fromEuros(150),
            RoutePattern::ThroughTicket,
        );

        self::assertSame('2026-10-29 09:00', $it->departAt()->format('Y-m-d H:i'));
        self::assertSame('2026-10-29 14:10', $it->arriveAt()->format('Y-m-d H:i'));
    }

    public function test_an_itinerary_without_segments_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Itinerary(Carrier::Klm, [], Money::fromEuros(100), RoutePattern::ThroughTicket);
    }

    public function test_more_than_one_connection_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Itinerary(
            Carrier::Klm,
            [
                self::segment(Airport::Ams, Airport::Cph, '2026-10-29 07:00', '2026-10-29 08:20', 'KL1'),
                self::segment(Airport::Cph, Airport::Arn, '2026-10-29 09:00', '2026-10-29 10:00', 'KL2'),
                self::segment(Airport::Arn, Airport::Tku, '2026-10-29 11:00', '2026-10-29 12:30', 'KL3'),
            ],
            Money::fromEuros(100),
            RoutePattern::ThroughTicket,
        );
    }

    private static function segment(Airport $from, Airport $to, string $dep, string $arr, string $nr): Segment
    {
        return new Segment(
            $from,
            $to,
            new \DateTimeImmutable($dep),
            new \DateTimeImmutable($arr),
            $nr,
        );
    }
}
```

- [ ] **Step 2: Draai de test en zie hem falen**

Run: `docker compose run --rm php bin/phpunit tests/Domain/Flight/ItineraryTest.php`
Expected: FAIL — `Class "App\Domain\Flight\Segment" not found`.

- [ ] **Step 3: Implementeer Segment**

Maak `api/src/Domain/Flight/Segment.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domain\Flight;

final readonly class Segment
{
    public function __construct(
        public Airport $from,
        public Airport $to,
        public \DateTimeImmutable $departAt,
        public \DateTimeImmutable $arriveAt,
        public string $flightNumber,
    ) {
        if ($arriveAt <= $departAt) {
            throw new \InvalidArgumentException('Aankomst moet na vertrek liggen.');
        }
    }
}
```

- [ ] **Step 4: Implementeer Itinerary**

Maak `api/src/Domain/Flight/Itinerary.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domain\Flight;

final readonly class Itinerary
{
    /**
     * @param Segment[] $segments
     */
    public function __construct(
        public Carrier $carrier,
        public array $segments,
        public Money $basePrice,
        public RoutePattern $pattern,
    ) {
        if ([] === $segments) {
            throw new \InvalidArgumentException('Een reisoptie heeft minstens één segment.');
        }

        if (count($segments) > 2) {
            throw new \InvalidArgumentException('Meer dan één overstap wordt niet ondersteund.');
        }
    }

    public function origin(): Airport
    {
        return $this->segments[0]->from;
    }

    public function destination(): Airport
    {
        return $this->segments[count($this->segments) - 1]->to;
    }

    public function departAt(): \DateTimeImmutable
    {
        return $this->segments[0]->departAt;
    }

    public function arriveAt(): \DateTimeImmutable
    {
        return $this->segments[count($this->segments) - 1]->arriveAt;
    }

    public function stopCount(): int
    {
        return count($this->segments) - 1;
    }

    public function connectionMinutes(): ?int
    {
        if (1 !== $this->stopCount()) {
            return null;
        }

        return intdiv(
            $this->segments[1]->departAt->getTimestamp() - $this->segments[0]->arriveAt->getTimestamp(),
            60,
        );
    }

    public function connectsVia(): ?Airport
    {
        return 1 === $this->stopCount() ? $this->segments[0]->to : null;
    }
}
```

- [ ] **Step 5: Draai de test**

Run: `docker compose run --rm php bin/phpunit tests/Domain/Flight/ItineraryTest.php`
Expected: PASS, 5 tests.

- [ ] **Step 6: Commit**

```bash
git add api/src/Domain/Flight api/tests/Domain/Flight
git commit -m "feat: segment en reisoptie met overstapinformatie"
```

---

### Task 5: FareUpgradeRates — faalt bij ontbrekende configuratie

**Files:**
- Create: `api/src/Domain/Pricing/FareUpgradeRates.php`, `api/src/Domain/Pricing/MissingFareUpgradeRate.php`
- Test: `api/tests/Domain/Pricing/FareUpgradeRatesTest.php`

**Interfaces:**
- Consumes: `Carrier`, `Money` (Taak 2)
- Produces:
  - `FareUpgradeRates::fromConfig(array $raw): self` — sleutels zijn Carrier-waarden (`'AY'`), waarden zijn euro's als `int|float`
  - `->for(Carrier): Money`
  - `->isEstimate(Carrier): bool` — `true` voor KLM (dynamisch geprijsd), `false` voor Finnair
  - `MissingFareUpgradeRate extends \RuntimeException`

Deze klasse is de plek waar de spec-eis "weigeren te starten bij een ontbrekende waarde" wordt afgedwongen. Een `null`, een lege string of een ontbrekende sleutel is een fout — géén stilzwijgende nul.

- [ ] **Step 1: Schrijf de falende test**

Maak `api/tests/Domain/Pricing/FareUpgradeRatesTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Domain\Pricing;

use App\Domain\Flight\Carrier;
use App\Domain\Pricing\FareUpgradeRates;
use App\Domain\Pricing\MissingFareUpgradeRate;
use PHPUnit\Framework\TestCase;

final class FareUpgradeRatesTest extends TestCase
{
    public function test_configured_rates_are_returned_as_money(): void
    {
        $rates = FareUpgradeRates::fromConfig(['AY' => 35, 'KL' => 40.50]);

        self::assertSame(3500, $rates->for(Carrier::Finnair)->cents);
        self::assertSame(4050, $rates->for(Carrier::Klm)->cents);
    }

    public function test_a_missing_key_throws(): void
    {
        $this->expectException(MissingFareUpgradeRate::class);
        $this->expectExceptionMessage('AY');

        FareUpgradeRates::fromConfig(['KL' => 40]);
    }

    public function test_a_null_value_throws_rather_than_becoming_zero(): void
    {
        $this->expectException(MissingFareUpgradeRate::class);

        FareUpgradeRates::fromConfig(['AY' => null, 'KL' => 40]);
    }

    public function test_klm_is_marked_as_an_estimate_and_finnair_is_not(): void
    {
        $rates = FareUpgradeRates::fromConfig(['AY' => 35, 'KL' => 40]);

        self::assertFalse($rates->isEstimate(Carrier::Finnair));
        self::assertTrue($rates->isEstimate(Carrier::Klm));
    }

    public function test_sas_needs_no_rate_because_baggage_is_never_bought_there(): void
    {
        $rates = FareUpgradeRates::fromConfig(['AY' => 35, 'KL' => 40]);

        self::assertTrue($rates->for(Carrier::Sas)->isZero());
    }
}
```

- [ ] **Step 2: Draai de test en zie hem falen**

Run: `docker compose run --rm php bin/phpunit tests/Domain/Pricing/FareUpgradeRatesTest.php`
Expected: FAIL — `Class "App\Domain\Pricing\FareUpgradeRates" not found`.

- [ ] **Step 3: Implementeer de exception**

Maak `api/src/Domain/Pricing/MissingFareUpgradeRate.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

final class MissingFareUpgradeRate extends \RuntimeException
{
    public static function for(string $carrierCode): self
    {
        return new self(sprintf(
            'Geen fare_upgrade-tarief geconfigureerd voor %s. Vul dit in; doorrekenen met €0 '
            .'zou Light-tarieven structureel te goedkoop laten lijken.',
            $carrierCode,
        ));
    }
}
```

- [ ] **Step 4: Implementeer FareUpgradeRates**

Maak `api/src/Domain/Pricing/FareUpgradeRates.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

use App\Domain\Flight\Carrier;
use App\Domain\Flight\Money;

final readonly class FareUpgradeRates
{
    /**
     * Maatschappijen waarvoor een tarief verplicht geconfigureerd moet zijn.
     * SAS staat hier niet bij: daar gaat handbagage als (gratis) ruimbagage mee.
     */
    private const REQUIRED = [Carrier::Finnair, Carrier::Klm];

    /** Maatschappijen die dynamisch prijzen; hun tarief is een schatting. */
    private const ESTIMATED = [Carrier::Klm];

    /**
     * @param array<string, Money> $rates
     */
    private function __construct(private array $rates)
    {
    }

    /**
     * @param array<string, int|float|null> $raw tarieven in euro's, gesleuteld op carriercode
     */
    public static function fromConfig(array $raw): self
    {
        $rates = [];

        foreach (self::REQUIRED as $carrier) {
            $value = $raw[$carrier->value] ?? null;

            if (!is_int($value) && !is_float($value)) {
                throw MissingFareUpgradeRate::for($carrier->value);
            }

            $rates[$carrier->value] = Money::fromEuros((float) $value);
        }

        return new self($rates);
    }

    public function for(Carrier $carrier): Money
    {
        return $this->rates[$carrier->value] ?? Money::zero();
    }

    public function isEstimate(Carrier $carrier): bool
    {
        return in_array($carrier, self::ESTIMATED, true);
    }
}
```

- [ ] **Step 5: Draai de test**

Run: `docker compose run --rm php bin/phpunit tests/Domain/Pricing/FareUpgradeRatesTest.php`
Expected: PASS, 5 tests.

- [ ] **Step 6: Commit**

```bash
git add api/src/Domain/Pricing api/tests/Domain/Pricing
git commit -m "feat: fare-upgrade-tarieven die falen bij ontbrekende configuratie"
```

---

### Task 6: BaggagePolicy — de kern van het prijsverschil tussen reizigers

**Files:**
- Create: `api/src/Domain/Pricing/BaggageEntitlement.php`, `api/src/Domain/Pricing/BaggagePolicy.php`
- Test: `api/tests/Domain/Pricing/BaggagePolicyTest.php`

**Interfaces:**
- Consumes: `Carrier`, `Money` (Taak 2), `LoyaltyStatus` (Taak 3), `FareUpgradeRates` (Taak 5)
- Produces:
  - `new BaggageEntitlement(bool $cabinBagIncluded, bool $checkedBagIncluded)`
  - `new BaggagePolicy(FareUpgradeRates $rates)`
  - `->entitlementFor(Carrier, LoyaltyStatus): BaggageEntitlement`
  - `->costFor(Carrier, LoyaltyStatus): Money`

De regels uit de spec, uitgeschreven:

| Maatschappij | Status | Handbagage gratis | Ruimbagage gratis | Kosten |
|---|---|---|---|---|
| Finnair | Silver | ja | nee | €0 |
| Finnair | geen | nee | nee | fare-upgrade AY |
| SAS | Silver | nee | **ja** | €0 — handbagage gaat als ruimbagage mee |
| SAS | geen | nee | nee | €0 — zie noot |
| KLM | (altijd geen) | nee | nee | fare-upgrade KL |

Noot bij SAS zonder status: die combinatie komt in fase 1 niet voor — beide reizigers hebben SAS Silver. De policy geeft er `Money::zero()` voor terug omdat er geen tarief voor SAS geconfigureerd is; als er ooit een reiziger zonder SAS-status bijkomt moet hier een tarief bij.

- [ ] **Step 1: Schrijf de falende test**

Maak `api/tests/Domain/Pricing/BaggagePolicyTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Domain\Pricing;

use App\Domain\Flight\Carrier;
use App\Domain\Pricing\BaggagePolicy;
use App\Domain\Pricing\FareUpgradeRates;
use App\Domain\Traveller\LoyaltyStatus;
use App\Domain\Traveller\Traveller;
use PHPUnit\Framework\TestCase;

final class BaggagePolicyTest extends TestCase
{
    private BaggagePolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new BaggagePolicy(
            FareUpgradeRates::fromConfig(['AY' => 35, 'KL' => 40]),
        );
    }

    public function test_finnair_silver_gets_cabin_baggage_for_free(): void
    {
        $entitlement = $this->policy->entitlementFor(Carrier::Finnair, LoyaltyStatus::Silver);

        self::assertTrue($entitlement->cabinBagIncluded);
        self::assertTrue($this->policy->costFor(Carrier::Finnair, LoyaltyStatus::Silver)->isZero());
    }

    public function test_finnair_without_status_pays_the_upgrade(): void
    {
        $entitlement = $this->policy->entitlementFor(Carrier::Finnair, LoyaltyStatus::None);

        self::assertFalse($entitlement->cabinBagIncluded);
        self::assertSame(3500, $this->policy->costFor(Carrier::Finnair, LoyaltyStatus::None)->cents);
    }

    public function test_sas_silver_pays_nothing_because_hand_luggage_goes_as_checked(): void
    {
        $entitlement = $this->policy->entitlementFor(Carrier::Sas, LoyaltyStatus::Silver);

        self::assertFalse($entitlement->cabinBagIncluded, 'SAS geeft geen gratis handbagage');
        self::assertTrue($entitlement->checkedBagIncluded, 'SAS Silver geeft wel gratis ruimbagage');
        self::assertTrue($this->policy->costFor(Carrier::Sas, LoyaltyStatus::Silver)->isZero());
    }

    public function test_klm_always_pays_the_upgrade(): void
    {
        self::assertSame(4000, $this->policy->costFor(Carrier::Klm, LoyaltyStatus::None)->cents);
    }

    public function test_the_same_finnair_flight_costs_the_partner_more_than_chris(): void
    {
        $chris = $this->policy->costFor(Carrier::Finnair, Traveller::chris()->statusFor(Carrier::Finnair));
        $partner = $this->policy->costFor(Carrier::Finnair, Traveller::partner()->statusFor(Carrier::Finnair));

        self::assertTrue($chris->isZero());
        self::assertSame(3500, $partner->cents);
    }

    public function test_sas_costs_them_the_same(): void
    {
        $chris = $this->policy->costFor(Carrier::Sas, Traveller::chris()->statusFor(Carrier::Sas));
        $partner = $this->policy->costFor(Carrier::Sas, Traveller::partner()->statusFor(Carrier::Sas));

        self::assertSame($chris->cents, $partner->cents);
        self::assertTrue($chris->isZero());
    }
}
```

- [ ] **Step 2: Draai de test en zie hem falen**

Run: `docker compose run --rm php bin/phpunit tests/Domain/Pricing/BaggagePolicyTest.php`
Expected: FAIL — `Class "App\Domain\Pricing\BaggagePolicy" not found`.

- [ ] **Step 3: Implementeer BaggageEntitlement**

Maak `api/src/Domain/Pricing/BaggageEntitlement.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

final readonly class BaggageEntitlement
{
    public function __construct(
        public bool $cabinBagIncluded,
        public bool $checkedBagIncluded,
    ) {
    }

    public function coversHandLuggage(): bool
    {
        // Handbagage is gedekt als die gratis mee mag, óf als ruimbagage gratis is:
        // in dat laatste geval checken we de handbagage gewoon in.
        return $this->cabinBagIncluded || $this->checkedBagIncluded;
    }
}
```

- [ ] **Step 4: Implementeer BaggagePolicy**

Maak `api/src/Domain/Pricing/BaggagePolicy.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

use App\Domain\Flight\Carrier;
use App\Domain\Flight\Money;
use App\Domain\Traveller\LoyaltyStatus;

final readonly class BaggagePolicy
{
    public function __construct(private FareUpgradeRates $rates)
    {
    }

    public function entitlementFor(Carrier $carrier, LoyaltyStatus $status): BaggageEntitlement
    {
        $silver = LoyaltyStatus::Silver === $status;

        return match ($carrier) {
            // Finnair Plus Silver geeft handbagage op een Light-tarief.
            Carrier::Finnair => new BaggageEntitlement($silver, false),
            // SAS EuroBonus Silver geeft ruimbagage, maar géén handbagage.
            Carrier::Sas => new BaggageEntitlement(false, $silver),
            // Bij KLM heeft niemand status.
            Carrier::Klm => new BaggageEntitlement(false, false),
        };
    }

    public function costFor(Carrier $carrier, LoyaltyStatus $status): Money
    {
        if ($this->entitlementFor($carrier, $status)->coversHandLuggage()) {
            return Money::zero();
        }

        return $this->rates->for($carrier);
    }
}
```

- [ ] **Step 5: Draai de test**

Run: `docker compose run --rm php bin/phpunit tests/Domain/Pricing/BaggagePolicyTest.php`
Expected: PASS, 6 tests.

- [ ] **Step 6: Draai de hele suite**

Run: `docker compose run --rm php bin/phpunit`
Expected: PASS, alle tests groen.

- [ ] **Step 7: Commit**

```bash
git add api/src/Domain/Pricing api/tests/Domain/Pricing
git commit -m "feat: bagagekosten per maatschappij en status"
```

---

### Task 7: Reisregels en RuleSet

**Files:**
- Create: `api/src/Domain/Rules/ItineraryRule.php`, `MaxConnectionDuration.php`, `AllowedCarriers.php`, `AllowedConnectionAirports.php`, `HelsinkiTimeWindow.php`, `RuleSet.php`
- Test: `api/tests/Domain/Rules/RuleSetTest.php`

**Interfaces:**
- Consumes: `Itinerary`, `Airport`, `Carrier`, `Direction`, `RoutePattern` (Taken 2, 4)
- Produces:
  - `interface ItineraryRule { public function isSatisfiedBy(Itinerary $i): bool; public function reasonFor(Itinerary $i): string; }`
  - `RuleSet::standard(Direction $direction): self`
  - `RuleSet->filter(array $itineraries): array` — behoudt sleutelloze, hernummerde lijst
  - `RuleSet->rejectionReason(Itinerary $i): ?string` — `null` als de optie geldig is

Er is bewust géén aparte `MaxOneConnection`-regel: `Itinerary` weigert in zijn
constructor al meer dan twee segmenten, dus een reisoptie met twee overstappen
kan niet eens bestaan. Een regel die nooit kan afgaan is dode code.

`reasonFor()` krijgt de reisoptie mee omdat sommige uitleg ernaar moet verwijzen
("overstap via HEL"). Zonder die parameter zou `RuleSet` met `instanceof` moeten
gaan uitzoeken welke regel een rijkere boodschap kan geven.

**Belangrijke ontwerpkeuze:** het tijdvenster voor Helsinki geldt alleen voor
`RoutePattern::ViaHelsinkiWithTrain`. Bij een doorgaand ticket naar TKU is er geen
trein te halen, dus de 21:00-grens slaat daar nergens op. Doorgaande tickets via
ARN of CPH worden dus niet op HEL-tijden getoetst.

- [ ] **Step 1: Schrijf de falende test**

Maak `api/tests/Domain/Rules/RuleSetTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Domain\Rules;

use App\Domain\Flight\Airport;
use App\Domain\Flight\Carrier;
use App\Domain\Flight\Direction;
use App\Domain\Flight\Itinerary;
use App\Domain\Flight\Money;
use App\Domain\Flight\RoutePattern;
use App\Domain\Flight\Segment;
use App\Domain\Rules\RuleSet;
use PHPUnit\Framework\TestCase;

final class RuleSetTest extends TestCase
{
    public function test_a_midday_finnair_flight_arriving_before_21_is_accepted(): void
    {
        $it = self::viaHel('2026-10-29 13:00', '2026-10-29 16:45', Carrier::Finnair);

        self::assertNull(RuleSet::standard(Direction::NlToFi)->rejectionReason($it));
    }

    public function test_arriving_in_helsinki_after_21_is_rejected(): void
    {
        $it = self::viaHel('2026-10-29 18:30', '2026-10-29 22:15', Carrier::Finnair);

        self::assertSame(
            'Aankomst in Helsinki na 21:00 — trein naar Turku niet meer haalbaar.',
            RuleSet::standard(Direction::NlToFi)->rejectionReason($it),
        );
    }

    public function test_departing_helsinki_before_noon_is_rejected(): void
    {
        $it = new Itinerary(
            Carrier::Finnair,
            [self::segment(Airport::Hel, Airport::Ams, '2026-10-25 08:00', '2026-10-25 09:50', 'AY841')],
            Money::fromEuros(80),
            RoutePattern::ViaHelsinkiWithTrain,
        );

        self::assertSame(
            'Vertrek uit Helsinki vóór 12:00.',
            RuleSet::standard(Direction::FiToNl)->rejectionReason($it),
        );
    }

    public function test_a_through_ticket_is_not_judged_on_helsinki_times(): void
    {
        $it = new Itinerary(
            Carrier::Sas,
            [
                self::segment(Airport::Ams, Airport::Arn, '2026-10-29 17:00', '2026-10-29 19:00', 'SK552'),
                self::segment(Airport::Arn, Airport::Tku, '2026-10-29 20:00', '2026-10-29 21:40', 'SK721'),
            ],
            Money::fromEuros(150),
            RoutePattern::ThroughTicket,
        );

        self::assertNull(RuleSet::standard(Direction::NlToFi)->rejectionReason($it));
    }

    public function test_a_connection_longer_than_two_hours_is_rejected(): void
    {
        $it = new Itinerary(
            Carrier::Sas,
            [
                self::segment(Airport::Ams, Airport::Arn, '2026-10-29 09:00', '2026-10-29 11:00', 'SK552'),
                self::segment(Airport::Arn, Airport::Tku, '2026-10-29 14:00', '2026-10-29 15:40', 'SK721'),
            ],
            Money::fromEuros(150),
            RoutePattern::ThroughTicket,
        );

        self::assertSame(
            'Overstaptijd langer dan 120 minuten.',
            RuleSet::standard(Direction::NlToFi)->rejectionReason($it),
        );
    }

    public function test_an_unwanted_connection_airport_is_rejected(): void
    {
        $it = new Itinerary(
            Carrier::Klm,
            [
                self::segment(Airport::Ams, Airport::Hel, '2026-10-29 09:00', '2026-10-29 12:40', 'KL1'),
                self::segment(Airport::Hel, Airport::Tku, '2026-10-29 13:30', '2026-10-29 14:20', 'KL2'),
            ],
            Money::fromEuros(200),
            RoutePattern::ThroughTicket,
        );

        self::assertSame(
            'Overstap via HEL wordt bij een doorgaand ticket niet overwogen.',
            RuleSet::standard(Direction::NlToFi)->rejectionReason($it),
        );
    }

    public function test_filter_keeps_only_valid_options_and_renumbers(): void
    {
        $good = self::viaHel('2026-10-29 13:00', '2026-10-29 16:45', Carrier::Finnair);
        $bad = self::viaHel('2026-10-29 18:30', '2026-10-29 22:15', Carrier::Finnair);

        $kept = RuleSet::standard(Direction::NlToFi)->filter([$bad, $good, $bad]);

        self::assertCount(1, $kept);
        self::assertSame([0], array_keys($kept));
        self::assertSame($good, $kept[0]);
    }

    private static function viaHel(string $dep, string $arr, Carrier $carrier): Itinerary
    {
        return new Itinerary(
            $carrier,
            [self::segment(Airport::Ams, Airport::Hel, $dep, $arr, 'AY842')],
            Money::fromEuros(134),
            RoutePattern::ViaHelsinkiWithTrain,
        );
    }

    private static function segment(Airport $from, Airport $to, string $dep, string $arr, string $nr): Segment
    {
        return new Segment($from, $to, new \DateTimeImmutable($dep), new \DateTimeImmutable($arr), $nr);
    }
}
```

- [ ] **Step 2: Draai de test en zie hem falen**

Run: `docker compose run --rm php bin/phpunit tests/Domain/Rules/RuleSetTest.php`
Expected: FAIL — `Class "App\Domain\Rules\RuleSet" not found`.

- [ ] **Step 3: Implementeer de interface**

Maak `api/src/Domain/Rules/ItineraryRule.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domain\Rules;

use App\Domain\Flight\Itinerary;

interface ItineraryRule
{
    public function isSatisfiedBy(Itinerary $itinerary): bool;

    /** Uitleg die aan de gebruiker getoond kan worden als de regel afwijst. */
    public function reasonFor(Itinerary $itinerary): string;
}
```

- [ ] **Step 4: Implementeer de losse regels**

Maak `api/src/Domain/Rules/MaxConnectionDuration.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domain\Rules;

use App\Domain\Flight\Itinerary;

final readonly class MaxConnectionDuration implements ItineraryRule
{
    public function __construct(private int $maxMinutes = 120)
    {
    }

    public function isSatisfiedBy(Itinerary $itinerary): bool
    {
        $minutes = $itinerary->connectionMinutes();

        return null === $minutes || $minutes <= $this->maxMinutes;
    }

    public function reasonFor(Itinerary $itinerary): string
    {
        return sprintf('Overstaptijd langer dan %d minuten.', $this->maxMinutes);
    }
}
```

Maak `api/src/Domain/Rules/AllowedCarriers.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domain\Rules;

use App\Domain\Flight\Carrier;
use App\Domain\Flight\Itinerary;

final readonly class AllowedCarriers implements ItineraryRule
{
    /** @var Carrier[] */
    private array $allowed;

    public function __construct(Carrier ...$allowed)
    {
        $this->allowed = [] === $allowed
            ? [Carrier::Finnair, Carrier::Sas, Carrier::Klm]
            : $allowed;
    }

    public function isSatisfiedBy(Itinerary $itinerary): bool
    {
        return in_array($itinerary->carrier, $this->allowed, true);
    }

    public function reasonFor(Itinerary $itinerary): string
    {
        return sprintf('Maatschappij %s valt buiten Finnair, SAS en KLM.', $itinerary->carrier->value);
    }
}
```

Maak `api/src/Domain/Rules/AllowedConnectionAirports.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domain\Rules;

use App\Domain\Flight\Airport;
use App\Domain\Flight\Itinerary;
use App\Domain\Flight\RoutePattern;

/**
 * Bij een doorgaand ticket zijn alleen Stockholm en Kopenhagen zinvolle
 * overstappunten. Helsinki valt hier bewust buiten: dat is patroon
 * via-Helsinki-met-trein, dat apart geprijsd wordt.
 */
final readonly class AllowedConnectionAirports implements ItineraryRule
{
    private const ALLOWED = [Airport::Arn, Airport::Cph];

    public function isSatisfiedBy(Itinerary $itinerary): bool
    {
        if (RoutePattern::ThroughTicket !== $itinerary->pattern) {
            return true;
        }

        $via = $itinerary->connectsVia();

        return null === $via || in_array($via, self::ALLOWED, true);
    }

    public function reasonFor(Itinerary $itinerary): string
    {
        return sprintf(
            'Overstap via %s wordt bij een doorgaand ticket niet overwogen.',
            $itinerary->connectsVia()?->value ?? '?',
        );
    }
}
```

Maak `api/src/Domain/Rules/HelsinkiTimeWindow.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domain\Rules;

use App\Domain\Flight\Direction;
use App\Domain\Flight\Itinerary;
use App\Domain\Flight\RoutePattern;

/**
 * Heen: je moet de trein naar Turku nog halen, dus aankomst uiterlijk 21:00.
 * Terug: geen nachtelijk vertrek uit Turku, dus vertrek niet vóór 12:00.
 *
 * Geldt alleen voor het patroon met trein. Een doorgaand ticket naar TKU
 * heeft geen aansluitende trein en wordt hier niet op getoetst.
 */
final readonly class HelsinkiTimeWindow implements ItineraryRule
{
    public function __construct(
        private Direction $direction,
        private int $latestArrivalMinutes = 21 * 60,
        private int $earliestDepartureMinutes = 12 * 60,
    ) {
    }

    public function isSatisfiedBy(Itinerary $itinerary): bool
    {
        if (RoutePattern::ViaHelsinkiWithTrain !== $itinerary->pattern) {
            return true;
        }

        if ($this->direction->isOutbound()) {
            // "uiterlijk 21:00" is inclusief: precies 21:00 mag nog.
            return self::minutesOfDay($itinerary->arriveAt()) <= $this->latestArrivalMinutes;
        }

        return self::minutesOfDay($itinerary->departAt()) >= $this->earliestDepartureMinutes;
    }

    public function reasonFor(Itinerary $itinerary): string
    {
        return $this->direction->isOutbound()
            ? 'Aankomst in Helsinki na 21:00 — trein naar Turku niet meer haalbaar.'
            : 'Vertrek uit Helsinki vóór 12:00.';
    }

    private static function minutesOfDay(\DateTimeImmutable $moment): int
    {
        return ((int) $moment->format('H')) * 60 + (int) $moment->format('i');
    }
}
```

- [ ] **Step 5: Implementeer RuleSet**

Maak `api/src/Domain/Rules/RuleSet.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domain\Rules;

use App\Domain\Flight\Direction;
use App\Domain\Flight\Itinerary;

final readonly class RuleSet
{
    /** @var ItineraryRule[] */
    private array $rules;

    public function __construct(ItineraryRule ...$rules)
    {
        $this->rules = $rules;
    }

    public static function standard(Direction $direction): self
    {
        // Volgorde bepaalt welke afwijzingsreden je te zien krijgt als een
        // optie meerdere regels breekt. Van grofst naar fijnst.
        return new self(
            new AllowedCarriers(),
            new AllowedConnectionAirports(),
            new MaxConnectionDuration(120),
            new HelsinkiTimeWindow($direction),
        );
    }

    /**
     * @param Itinerary[] $itineraries
     *
     * @return Itinerary[] hernummerd vanaf 0
     */
    public function filter(array $itineraries): array
    {
        return array_values(array_filter(
            $itineraries,
            fn (Itinerary $i): bool => null === $this->rejectionReason($i),
        ));
    }

    public function rejectionReason(Itinerary $itinerary): ?string
    {
        foreach ($this->rules as $rule) {
            if (!$rule->isSatisfiedBy($itinerary)) {
                return $rule->reasonFor($itinerary);
            }
        }

        return null;
    }
}
```

- [ ] **Step 6: Draai de test**

Run: `docker compose run --rm php bin/phpunit tests/Domain/Rules/RuleSetTest.php`
Expected: PASS, 7 tests.

- [ ] **Step 7: Commit**

```bash
git add api/src/Domain/Rules api/tests/Domain/Rules
git commit -m "feat: reisregels voor overstap, maatschappij en tijdvenster"
```

---

### Task 8: TotalCost en TotalCostCalculator

**Files:**
- Create: `api/src/Domain/Pricing/TotalCost.php`, `api/src/Domain/Pricing/TotalCostCalculator.php`
- Test: `api/tests/Domain/Pricing/TotalCostCalculatorTest.php`

**Interfaces:**
- Consumes: `Itinerary`, `Money`, `RoutePattern`, `Carrier` (Taken 2, 4), `Traveller` (Taak 3), `BaggagePolicy`, `FareUpgradeRates` (Taken 5, 6)
- Produces:
  - `new TotalCost(Money $basePrice, Money $baggageCost, Money $trainCost, bool $indicative)`
  - `TotalCost->total(): Money`
  - `new TotalCostCalculator(BaggagePolicy $baggage, FareUpgradeRates $rates)`
  - `->calculate(Itinerary $i, Traveller $t, ?Money $trainCost): TotalCost`

De treinprijs wordt **alleen** opgeteld bij `RoutePattern::ViaHelsinkiWithTrain`.
Zonder die correctie lijkt via-Helsinki structureel te goedkoop.

`indicative` is `true` zodra de uitkomst afhangt van een geschat tarief — dus bij
KLM met bagagekosten boven nul.

- [ ] **Step 1: Schrijf de falende test**

Maak `api/tests/Domain/Pricing/TotalCostCalculatorTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Domain\Pricing;

use App\Domain\Flight\Airport;
use App\Domain\Flight\Carrier;
use App\Domain\Flight\Itinerary;
use App\Domain\Flight\Money;
use App\Domain\Flight\RoutePattern;
use App\Domain\Flight\Segment;
use App\Domain\Pricing\BaggagePolicy;
use App\Domain\Pricing\FareUpgradeRates;
use App\Domain\Pricing\TotalCostCalculator;
use App\Domain\Traveller\Traveller;
use PHPUnit\Framework\TestCase;

final class TotalCostCalculatorTest extends TestCase
{
    private TotalCostCalculator $calculator;

    protected function setUp(): void
    {
        $rates = FareUpgradeRates::fromConfig(['AY' => 35, 'KL' => 40]);
        $this->calculator = new TotalCostCalculator(new BaggagePolicy($rates), $rates);
    }

    public function test_train_cost_is_added_for_the_helsinki_pattern(): void
    {
        $cost = $this->calculator->calculate(
            self::viaHel(Carrier::Finnair, 134),
            Traveller::chris(),
            Money::fromEuros(22.50),
        );

        self::assertSame(13400, $cost->basePrice->cents);
        self::assertSame(2250, $cost->trainCost->cents);
        self::assertSame(15650, $cost->total()->cents);
    }

    public function test_train_cost_is_ignored_for_a_through_ticket(): void
    {
        $cost = $this->calculator->calculate(
            self::throughTicket(Carrier::Sas, 150),
            Traveller::chris(),
            Money::fromEuros(22.50),
        );

        self::assertTrue($cost->trainCost->isZero());
        self::assertSame(15000, $cost->total()->cents);
    }

    public function test_the_partner_pays_more_than_chris_on_the_same_finnair_flight(): void
    {
        $flight = self::viaHel(Carrier::Finnair, 134);
        $train = Money::fromEuros(22.50);

        $chris = $this->calculator->calculate($flight, Traveller::chris(), $train);
        $partner = $this->calculator->calculate($flight, Traveller::partner(), $train);

        self::assertSame(15650, $chris->total()->cents);
        self::assertSame(19150, $partner->total()->cents, 'partner betaalt €35 handbagage extra');
    }

    public function test_klm_results_are_flagged_as_indicative(): void
    {
        $cost = $this->calculator->calculate(
            self::throughTicket(Carrier::Klm, 120),
            Traveller::chris(),
            null,
        );

        self::assertTrue($cost->indicative);
        self::assertSame(16000, $cost->total()->cents);
    }

    public function test_finnair_results_are_not_indicative(): void
    {
        $cost = $this->calculator->calculate(
            self::viaHel(Carrier::Finnair, 134),
            Traveller::partner(),
            Money::fromEuros(22.50),
        );

        self::assertFalse($cost->indicative, 'Finnair hanteert een vast tarief');
    }

    public function test_sas_is_never_indicative_because_no_estimate_is_used(): void
    {
        $cost = $this->calculator->calculate(
            self::throughTicket(Carrier::Sas, 150),
            Traveller::partner(),
            null,
        );

        self::assertFalse($cost->indicative);
        self::assertTrue($cost->baggageCost->isZero());
    }

    private static function viaHel(Carrier $carrier, float $euros): Itinerary
    {
        return new Itinerary(
            $carrier,
            [new Segment(
                Airport::Ams,
                Airport::Hel,
                new \DateTimeImmutable('2026-10-29 13:00'),
                new \DateTimeImmutable('2026-10-29 16:45'),
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
```

- [ ] **Step 2: Draai de test en zie hem falen**

Run: `docker compose run --rm php bin/phpunit tests/Domain/Pricing/TotalCostCalculatorTest.php`
Expected: FAIL — `Class "App\Domain\Pricing\TotalCostCalculator" not found`.

- [ ] **Step 3: Implementeer TotalCost**

Maak `api/src/Domain/Pricing/TotalCost.php`:

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
    ) {
    }

    public function total(): Money
    {
        return $this->basePrice->add($this->baggageCost)->add($this->trainCost);
    }
}
```

- [ ] **Step 4: Implementeer TotalCostCalculator**

Maak `api/src/Domain/Pricing/TotalCostCalculator.php`:

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

        $train = RoutePattern::ViaHelsinkiWithTrain === $itinerary->pattern
            ? ($trainCost ?? Money::zero())
            : Money::zero();

        // De uitkomst is alleen onzeker als er daadwerkelijk een geschat
        // tarief in meegerekend is.
        $indicative = !$baggageCost->isZero() && $this->rates->isEstimate($itinerary->carrier);

        return new TotalCost($itinerary->basePrice, $baggageCost, $train, $indicative);
    }
}
```

- [ ] **Step 5: Draai de test**

Run: `docker compose run --rm php bin/phpunit tests/Domain/Pricing/TotalCostCalculatorTest.php`
Expected: PASS, 6 tests.

- [ ] **Step 6: Commit**

```bash
git add api/src/Domain/Pricing api/tests/Domain/Pricing
git commit -m "feat: totaalprijs met trein en bagage per reiziger"
```

---

### Task 9: Poorten naar de buitenwereld

**Files:**
- Create: `api/src/Domain/Port/FlightPriceSource.php`, `TrainPriceSource.php`, `PriceHistory.php`
- Create: `api/src/Infrastructure/Persistence/NullPriceHistory.php`
- Test: `api/tests/Domain/Port/InMemoryFlightPriceSourceTest.php`
- Create: `api/tests/Double/InMemoryFlightPriceSource.php`, `api/tests/Double/FixedTrainPriceSource.php`

**Interfaces:**
- Consumes: `Airport`, `Itinerary`, `Money`, `RoutePattern` (Taken 2, 4)
- Produces:
  - `FlightPriceSource->search(Airport $from, Airport $to, \DateTimeImmutable $date): array` — geeft `Itinerary[]`
  - `TrainPriceSource->priceFor(\DateTimeImmutable $date): Money` — HEL ⇄ TKU, richting maakt niet uit
  - `PriceHistory->record(Itinerary $itinerary, \DateTimeImmutable $observedAt): void`
  - Testdubbels `InMemoryFlightPriceSource` en `FixedTrainPriceSource` voor latere taken

`NullPriceHistory` is bewust een no-op. De spec zet prijsgeschiedenis in fase 2;
de poort bestaat nu al zodat de zoekstroom in Taak 12 compleet is en fase 2
alleen een adapter hoeft toe te voegen zonder de use case aan te raken.

- [ ] **Step 1: Schrijf de interfaces**

Maak `api/src/Domain/Port/FlightPriceSource.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domain\Port;

use App\Domain\Flight\Airport;
use App\Domain\Flight\Itinerary;

interface FlightPriceSource
{
    /**
     * @return Itinerary[] ongefilterd; de regels worden later toegepast
     */
    public function search(Airport $from, Airport $to, \DateTimeImmutable $date): array;
}
```

Maak `api/src/Domain/Port/TrainPriceSource.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domain\Port;

use App\Domain\Flight\Money;

interface TrainPriceSource
{
    /** Goedkoopste VR-prijs Helsinki ⇄ Turku op deze datum. */
    public function priceFor(\DateTimeImmutable $date): Money;
}
```

Maak `api/src/Domain/Port/PriceHistory.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domain\Port;

use App\Domain\Flight\Itinerary;

interface PriceHistory
{
    public function record(Itinerary $itinerary, \DateTimeImmutable $observedAt): void;
}
```

- [ ] **Step 2: Schrijf de no-op implementatie**

Maak `api/src/Infrastructure/Persistence/NullPriceHistory.php`:

```php
<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Flight\Itinerary;
use App\Domain\Port\PriceHistory;

/**
 * Fase 1 bewaart nog geen prijsgeschiedenis. Fase 2 vervangt deze klasse
 * door een SQLite-implementatie zonder dat de use case wijzigt.
 */
final class NullPriceHistory implements PriceHistory
{
    public function record(Itinerary $itinerary, \DateTimeImmutable $observedAt): void
    {
    }
}
```

- [ ] **Step 3: Schrijf de testdubbels**

Maak `api/tests/Double/InMemoryFlightPriceSource.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Double;

use App\Domain\Flight\Airport;
use App\Domain\Flight\Itinerary;
use App\Domain\Port\FlightPriceSource;

final class InMemoryFlightPriceSource implements FlightPriceSource
{
    /** @var array<string, Itinerary[]> */
    private array $byRoute = [];

    /** @var array<int, array{Airport, Airport, string}> */
    public array $calls = [];

    /**
     * @param Itinerary[] $itineraries
     */
    public function give(Airport $from, Airport $to, array $itineraries): void
    {
        $this->byRoute[$from->value.'-'.$to->value] = $itineraries;
    }

    public function search(Airport $from, Airport $to, \DateTimeImmutable $date): array
    {
        $this->calls[] = [$from, $to, $date->format('Y-m-d')];

        return $this->byRoute[$from->value.'-'.$to->value] ?? [];
    }
}
```

Maak `api/tests/Double/FixedTrainPriceSource.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Double;

use App\Domain\Flight\Money;
use App\Domain\Port\TrainPriceSource;

final class FixedTrainPriceSource implements TrainPriceSource
{
    public int $callCount = 0;

    public function __construct(private readonly Money $price)
    {
    }

    public function priceFor(\DateTimeImmutable $date): Money
    {
        ++$this->callCount;

        return $this->price;
    }
}
```

- [ ] **Step 4: Schrijf een test die de dubbels bewijst**

Maak `api/tests/Domain/Port/InMemoryFlightPriceSourceTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Domain\Port;

use App\Domain\Flight\Airport;
use App\Domain\Flight\Carrier;
use App\Domain\Flight\Itinerary;
use App\Domain\Flight\Money;
use App\Domain\Flight\RoutePattern;
use App\Domain\Flight\Segment;
use App\Tests\Double\FixedTrainPriceSource;
use App\Tests\Double\InMemoryFlightPriceSource;
use PHPUnit\Framework\TestCase;

final class InMemoryFlightPriceSourceTest extends TestCase
{
    public function test_it_returns_what_was_given_for_that_route(): void
    {
        $source = new InMemoryFlightPriceSource();
        $source->give(Airport::Ams, Airport::Hel, [self::anItinerary()]);

        $found = $source->search(Airport::Ams, Airport::Hel, new \DateTimeImmutable('2026-10-29'));

        self::assertCount(1, $found);
        self::assertSame([[Airport::Ams, Airport::Hel, '2026-10-29']], $source->calls);
    }

    public function test_an_unknown_route_yields_nothing(): void
    {
        $source = new InMemoryFlightPriceSource();

        self::assertSame([], $source->search(Airport::Ams, Airport::Tku, new \DateTimeImmutable('2026-10-29')));
    }

    public function test_the_train_double_counts_its_calls(): void
    {
        $train = new FixedTrainPriceSource(Money::fromEuros(22.50));

        $train->priceFor(new \DateTimeImmutable('2026-10-29'));

        self::assertSame(1, $train->callCount);
        self::assertSame(2250, $train->priceFor(new \DateTimeImmutable('2026-10-29'))->cents);
    }

    private static function anItinerary(): Itinerary
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
```

- [ ] **Step 5: Zet de test-namespace klaar**

Voeg toe aan `api/composer.json` onder `autoload-dev.psr-4` (staat er al als `App\Tests\`), en draai:

```bash
docker compose run --rm php composer dump-autoload
docker compose run --rm php bin/phpunit tests/Domain/Port/InMemoryFlightPriceSourceTest.php
```

Expected: PASS, 3 tests.

- [ ] **Step 6: Commit**

```bash
git add api/src/Domain/Port api/src/Infrastructure/Persistence api/tests/Double api/tests/Domain/Port
git commit -m "feat: poorten voor prijsbronnen en prijsgeschiedenis"
```

---

### Task 10: Bright Data-adapter voor vluchtprijzen

**Files:**
- Create: `api/src/Infrastructure/Source/BrightDataFlightSource.php`
- Create: `api/tests/Fixtures/brightdata-ams-hel.json`
- Test: `api/tests/Infrastructure/Source/BrightDataFlightSourceTest.php`
- Modify: `api/config/services.yaml`

**Interfaces:**
- Consumes: `FlightPriceSource` (Taak 9), `Itinerary`, `Segment`, `Money`, `Carrier`, `Airport`, `RoutePattern` (Taken 2, 4)
- Produces: `BrightDataFlightSource implements FlightPriceSource`, geconstrueerd met `(HttpClientInterface $http, string $apiToken, string $zone)`

De adapter vertaalt Bright Data's Google Flights-respons naar `Itinerary`-objecten.
Het patroon wordt afgeleid uit de bestemming: eindigt de reis op `HEL`, dan is het
`ViaHelsinkiWithTrain`; eindigt hij op `TKU`, dan `ThroughTicket`.

- [ ] **Step 1: Leg een respons vast als fixture**

Maak `api/tests/Fixtures/brightdata-ams-hel.json`:

```json
{
  "best_flights": [
    {
      "flights": [
        {
          "departure_airport": {"id": "AMS", "time": "2026-10-29 13:00"},
          "arrival_airport": {"id": "HEL", "time": "2026-10-29 16:45"},
          "airline": "Finnair",
          "flight_number": "AY 842"
        }
      ],
      "price": 134,
      "total_duration": 165
    },
    {
      "flights": [
        {
          "departure_airport": {"id": "AMS", "time": "2026-10-29 18:30"},
          "arrival_airport": {"id": "HEL", "time": "2026-10-29 22:15"},
          "airline": "Finnair",
          "flight_number": "AY 844"
        }
      ],
      "price": 118,
      "total_duration": 165
    }
  ],
  "other_flights": [
    {
      "flights": [
        {
          "departure_airport": {"id": "AMS", "time": "2026-10-29 07:10"},
          "arrival_airport": {"id": "HEL", "time": "2026-10-29 10:55"},
          "airline": "KLM",
          "flight_number": "KL 1163"
        }
      ],
      "price": 156,
      "total_duration": 165
    }
  ]
}
```

- [ ] **Step 2: Schrijf de falende test**

Maak `api/tests/Infrastructure/Source/BrightDataFlightSourceTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Source;

use App\Domain\Flight\Airport;
use App\Domain\Flight\Carrier;
use App\Domain\Flight\RoutePattern;
use App\Infrastructure\Source\BrightDataFlightSource;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class BrightDataFlightSourceTest extends TestCase
{
    public function test_it_maps_a_recorded_response_to_itineraries(): void
    {
        $source = self::sourceReturning(self::fixture());

        $found = $source->search(Airport::Ams, Airport::Hel, new \DateTimeImmutable('2026-10-29'));

        self::assertCount(3, $found, 'best_flights en other_flights worden samengevoegd');

        $first = $found[0];
        self::assertSame(Carrier::Finnair, $first->carrier);
        self::assertSame(13400, $first->basePrice->cents);
        self::assertSame('2026-10-29 13:00', $first->departAt()->format('Y-m-d H:i'));
        self::assertSame('2026-10-29 16:45', $first->arriveAt()->format('Y-m-d H:i'));
        self::assertSame('AY842', $first->segments[0]->flightNumber);
    }

    public function test_a_journey_ending_in_helsinki_gets_the_train_pattern(): void
    {
        $source = self::sourceReturning(self::fixture());

        $found = $source->search(Airport::Ams, Airport::Hel, new \DateTimeImmutable('2026-10-29'));

        self::assertSame(RoutePattern::ViaHelsinkiWithTrain, $found[0]->pattern);
    }

    public function test_unknown_airlines_are_skipped_rather_than_crashing(): void
    {
        $source = self::sourceReturning(json_encode([
            'best_flights' => [[
                'flights' => [[
                    'departure_airport' => ['id' => 'AMS', 'time' => '2026-10-29 06:00'],
                    'arrival_airport' => ['id' => 'HEL', 'time' => '2026-10-29 09:45'],
                    'airline' => 'Ryanair',
                    'flight_number' => 'FR 123',
                ]],
                'price' => 49,
            ]],
        ], JSON_THROW_ON_ERROR));

        self::assertSame([], $source->search(Airport::Ams, Airport::Hel, new \DateTimeImmutable('2026-10-29')));
    }

    public function test_an_empty_response_yields_no_itineraries(): void
    {
        $source = self::sourceReturning('{}');

        self::assertSame([], $source->search(Airport::Ams, Airport::Hel, new \DateTimeImmutable('2026-10-29')));
    }

    public function test_the_request_carries_the_api_token(): void
    {
        $seen = null;
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$seen) {
            $seen = $options;

            return new MockResponse(self::fixture());
        });

        (new BrightDataFlightSource($client, 'test-token', 'serp_zone'))
            ->search(Airport::Ams, Airport::Hel, new \DateTimeImmutable('2026-10-29'));

        self::assertContains('Authorization: Bearer test-token', $seen['headers']);
    }

    private static function sourceReturning(string $body): BrightDataFlightSource
    {
        return new BrightDataFlightSource(
            new MockHttpClient(new MockResponse($body)),
            'test-token',
            'serp_zone',
        );
    }

    private static function fixture(): string
    {
        return file_get_contents(__DIR__.'/../../Fixtures/brightdata-ams-hel.json');
    }
}
```

- [ ] **Step 3: Draai de test en zie hem falen**

Run: `docker compose run --rm php bin/phpunit tests/Infrastructure/Source/BrightDataFlightSourceTest.php`
Expected: FAIL — `Class "App\Infrastructure\Source\BrightDataFlightSource" not found`.

- [ ] **Step 4: Installeer de HTTP-client**

```bash
docker compose run --rm php composer require symfony/http-client --no-interaction
```

- [ ] **Step 5: Implementeer de adapter**

Maak `api/src/Infrastructure/Source/BrightDataFlightSource.php`:

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
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class BrightDataFlightSource implements FlightPriceSource
{
    private const ENDPOINT = 'https://api.brightdata.com/request';

    /** Google Flights noemt maatschappijen bij naam, wij werken met codes. */
    private const AIRLINES = [
        'Finnair' => Carrier::Finnair,
        'SAS' => Carrier::Sas,
        'Scandinavian Airlines' => Carrier::Sas,
        'KLM' => Carrier::Klm,
    ];

    public function __construct(
        private HttpClientInterface $http,
        private string $apiToken,
        private string $zone,
    ) {
    }

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

        foreach ($raw as $offer) {
            $itinerary = $this->toItinerary($offer);

            if (null !== $itinerary) {
                $itineraries[] = $itinerary;
            }
        }

        return $itineraries;
    }

    /**
     * @param array<string, mixed> $offer
     */
    private function toItinerary(array $offer): ?Itinerary
    {
        $legs = $offer['flights'] ?? [];

        if ([] === $legs || !isset($offer['price'])) {
            return null;
        }

        $carrier = self::AIRLINES[$legs[0]['airline'] ?? ''] ?? null;

        if (null === $carrier) {
            // Een maatschappij buiten Finnair/SAS/KLM. Overslaan in plaats van
            // afbreken: één onbekende aanbieder mag de hele zoekopdracht niet slopen.
            return null;
        }

        $segments = [];

        foreach ($legs as $leg) {
            $from = Airport::tryFrom($leg['departure_airport']['id'] ?? '');
            $to = Airport::tryFrom($leg['arrival_airport']['id'] ?? '');

            if (null === $from || null === $to) {
                return null;
            }

            $segments[] = new Segment(
                $from,
                $to,
                new \DateTimeImmutable($leg['departure_airport']['time']),
                new \DateTimeImmutable($leg['arrival_airport']['time']),
                str_replace(' ', '', (string) ($leg['flight_number'] ?? '')),
            );
        }

        if (count($segments) > 2) {
            return null;
        }

        $destination = $segments[count($segments) - 1]->to;

        return new Itinerary(
            $carrier,
            $segments,
            Money::fromEuros((float) $offer['price']),
            Airport::Hel === $destination ? RoutePattern::ViaHelsinkiWithTrain : RoutePattern::ThroughTicket,
        );
    }

    private function googleFlightsUrl(Airport $from, Airport $to, \DateTimeImmutable $date): string
    {
        return sprintf(
            'https://www.google.com/travel/flights?q=Flights%%20to%%20%s%%20from%%20%s%%20on%%20%s&curr=EUR&hl=nl',
            $to->value,
            $from->value,
            $date->format('Y-m-d'),
        );
    }
}
```

- [ ] **Step 6: Draai de test**

Run: `docker compose run --rm php bin/phpunit tests/Infrastructure/Source/BrightDataFlightSourceTest.php`
Expected: PASS, 5 tests.

- [ ] **Step 7: Bedraad de service**

Voeg toe aan `api/config/services.yaml` onder `services:`:

```yaml
    App\Infrastructure\Source\BrightDataFlightSource:
        arguments:
            $apiToken: '%env(BRIGHTDATA_TOKEN)%'
            $zone: '%env(BRIGHTDATA_ZONE)%'

    App\Domain\Port\FlightPriceSource: '@App\Infrastructure\Source\BrightDataFlightSource'
    App\Domain\Port\PriceHistory: '@App\Infrastructure\Persistence\NullPriceHistory'
```

Voeg toe aan `api/.env`:

```
BRIGHTDATA_TOKEN=
BRIGHTDATA_ZONE=serp_api
```

- [ ] **Step 8: Commit**

```bash
git add api/src/Infrastructure api/tests/Infrastructure api/tests/Fixtures api/config/services.yaml api/.env api/composer.json api/composer.lock
git commit -m "feat: bright data-adapter voor vluchtprijzen"
```

---

### Task 11: Apify-adapter voor VR-treinprijzen, met cache

**Files:**
- Create: `api/src/Infrastructure/Source/ApifyTrainPriceSource.php`
- Create: `api/tests/Fixtures/apify-vr-hel-tku.json`
- Test: `api/tests/Infrastructure/Source/ApifyTrainPriceSourceTest.php`
- Modify: `api/config/services.yaml`, `api/.env`

**Interfaces:**
- Consumes: `TrainPriceSource` (Taak 9), `Money` (Taak 2)
- Produces: `ApifyTrainPriceSource implements TrainPriceSource`, geconstrueerd met `(HttpClientInterface $http, CacheInterface $cache, string $apiToken)`

Apify's gratis tegoed is $5 per maand, ruwweg 20–50 draaibeurten. De cache is
daarom geen optimalisatie maar een eis: zonder cache is het budget binnen een
week op. TTL 24 uur.

- [ ] **Step 1: Leg een respons vast als fixture**

Maak `api/tests/Fixtures/apify-vr-hel-tku.json`:

```json
[
  {"departureTime": "2026-10-29T08:04:00", "arrivalTime": "2026-10-29T10:02:00", "trainType": "IC", "priceEur": 34.9},
  {"departureTime": "2026-10-29T17:04:00", "arrivalTime": "2026-10-29T19:02:00", "trainType": "S", "priceEur": 22.5},
  {"departureTime": "2026-10-29T21:04:00", "arrivalTime": "2026-10-29T23:02:00", "trainType": "IC", "priceEur": 29.0}
]
```

- [ ] **Step 2: Schrijf de falende test**

Maak `api/tests/Infrastructure/Source/ApifyTrainPriceSourceTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Source;

use App\Infrastructure\Source\ApifyTrainPriceSource;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class ApifyTrainPriceSourceTest extends TestCase
{
    public function test_it_returns_the_cheapest_train_of_the_day(): void
    {
        $source = new ApifyTrainPriceSource(
            new MockHttpClient(new MockResponse(self::fixture())),
            new ArrayAdapter(),
            'test-token',
        );

        $price = $source->priceFor(new \DateTimeImmutable('2026-10-29'));

        self::assertSame(2250, $price->cents);
    }

    public function test_a_second_call_for_the_same_date_does_not_hit_apify_again(): void
    {
        $calls = 0;
        $client = new MockHttpClient(function () use (&$calls) {
            ++$calls;

            return new MockResponse(self::fixture());
        });

        $source = new ApifyTrainPriceSource($client, new ArrayAdapter(), 'test-token');

        $source->priceFor(new \DateTimeImmutable('2026-10-29'));
        $source->priceFor(new \DateTimeImmutable('2026-10-29'));

        self::assertSame(1, $calls, 'het Apify-tegoed is te klein om dit ongecached te doen');
    }

    public function test_different_dates_are_cached_separately(): void
    {
        $calls = 0;
        $client = new MockHttpClient(function () use (&$calls) {
            ++$calls;

            return new MockResponse(self::fixture());
        });

        $source = new ApifyTrainPriceSource($client, new ArrayAdapter(), 'test-token');

        $source->priceFor(new \DateTimeImmutable('2026-10-25'));
        $source->priceFor(new \DateTimeImmutable('2026-10-29'));

        self::assertSame(2, $calls);
    }

    public function test_an_empty_result_yields_zero_rather_than_crashing(): void
    {
        $source = new ApifyTrainPriceSource(
            new MockHttpClient(new MockResponse('[]')),
            new ArrayAdapter(),
            'test-token',
        );

        self::assertTrue($source->priceFor(new \DateTimeImmutable('2026-10-29'))->isZero());
    }

    private static function fixture(): string
    {
        return file_get_contents(__DIR__.'/../../Fixtures/apify-vr-hel-tku.json');
    }
}
```

- [ ] **Step 3: Draai de test en zie hem falen**

Run: `docker compose run --rm php bin/phpunit tests/Infrastructure/Source/ApifyTrainPriceSourceTest.php`
Expected: FAIL — `Class "App\Infrastructure\Source\ApifyTrainPriceSource" not found`.

- [ ] **Step 4: Installeer de cache-component**

```bash
docker compose run --rm php composer require symfony/cache --no-interaction
```

- [ ] **Step 5: Implementeer de adapter**

Maak `api/src/Infrastructure/Source/ApifyTrainPriceSource.php`:

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

    public function __construct(
        private HttpClientInterface $http,
        private CacheItemPoolInterface $cache,
        private string $apiToken,
    ) {
    }

    public function priceFor(\DateTimeImmutable $date): Money
    {
        $item = $this->cache->getItem('vr.hel-tku.'.$date->format('Y-m-d'));

        if ($item->isHit()) {
            return Money::fromCents((int) $item->get());
        }

        $price = $this->fetch($date);

        $item->set($price->cents);
        $item->expiresAfter(self::TTL_SECONDS);
        $this->cache->save($item);

        return $price;
    }

    private function fetch(\DateTimeImmutable $date): Money
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
            static fn (array $row): ?float => isset($row['priceEur']) ? (float) $row['priceEur'] : null,
            is_array($rows) ? $rows : [],
        ));

        if ([] === $prices) {
            return Money::zero();
        }

        return Money::fromEuros(min($prices));
    }
}
```

- [ ] **Step 6: Draai de test**

Run: `docker compose run --rm php bin/phpunit tests/Infrastructure/Source/ApifyTrainPriceSourceTest.php`
Expected: PASS, 4 tests.

- [ ] **Step 7: Bedraad de service**

Voeg toe aan `api/config/services.yaml`:

```yaml
    App\Infrastructure\Source\ApifyTrainPriceSource:
        arguments:
            $cache: '@cache.app'
            $apiToken: '%env(APIFY_TOKEN)%'

    App\Domain\Port\TrainPriceSource: '@App\Infrastructure\Source\ApifyTrainPriceSource'
```

Voeg toe aan `api/.env`:

```
APIFY_TOKEN=
```

- [ ] **Step 8: Commit**

```bash
git add api/src/Infrastructure api/tests/Infrastructure api/tests/Fixtures api/config/services.yaml api/.env api/composer.json api/composer.lock
git commit -m "feat: apify-adapter voor vr-treinprijzen met dagcache"
```

---

### Task 12: SearchFlights use case

**Files:**
- Create: `api/src/Application/SearchFlights/SearchFlightsQuery.php`, `PricedItinerary.php`, `SearchResult.php`, `SearchFlightsHandler.php`
- Test: `api/tests/Application/SearchFlights/SearchFlightsHandlerTest.php`

**Interfaces:**
- Consumes: alles uit Taken 2–9
- Produces:
  - `new SearchFlightsQuery(\DateTimeImmutable $date, Direction $direction, string $travellerId)`
  - `PricedItinerary->itinerary: Itinerary`, `->cost: TotalCost`
  - `SearchResult->options: PricedItinerary[]` (gesorteerd, goedkoopste eerst), `->traveller: Traveller`, `->searchedAt: \DateTimeImmutable`
  - `SearchFlightsHandler->__invoke(SearchFlightsQuery): SearchResult`

De handler zoekt **twee** routes en voegt ze samen: AMS⇄HEL (patroon trein) en
AMS⇄TKU (doorgaand). De treinprijs wordt maar één keer opgehaald, en alleen als
er daadwerkelijk een HEL-optie overblijft — dat scheelt Apify-tegoed.

- [ ] **Step 1: Schrijf de falende test**

Maak `api/tests/Application/SearchFlights/SearchFlightsHandlerTest.php`:

```php
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
use App\Tests\Double\FixedTrainPriceSource;
use App\Tests\Double\InMemoryFlightPriceSource;
use PHPUnit\Framework\TestCase;

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

        $result = $this->handler($flights, new FixedTrainPriceSource(Money::fromEuros(22.50)))(
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

        $result = $this->handler($flights, new FixedTrainPriceSource(Money::fromEuros(22.50)))(
            new SearchFlightsQuery(new \DateTimeImmutable('2026-10-29'), Direction::NlToFi, 'chris'),
        );

        self::assertCount(1, $result->options, 'de goedkopere avondvlucht valt af op aankomsttijd');
        self::assertSame(13400, $result->options[0]->itinerary->basePrice->cents);
    }

    public function test_the_partner_gets_a_higher_total_on_finnair(): void
    {
        $flights = new InMemoryFlightPriceSource();
        $flights->give(Airport::Ams, Airport::Hel, [self::viaHel(Carrier::Finnair, 134, '13:00', '16:45')]);

        $handler = $this->handler($flights, new FixedTrainPriceSource(Money::fromEuros(22.50)));

        $chris = $handler(new SearchFlightsQuery(new \DateTimeImmutable('2026-10-29'), Direction::NlToFi, 'chris'));
        $partner = $handler(new SearchFlightsQuery(new \DateTimeImmutable('2026-10-29'), Direction::NlToFi, 'partner'));

        self::assertSame(15650, $chris->options[0]->cost->total()->cents);
        self::assertSame(19150, $partner->options[0]->cost->total()->cents);
    }

    public function test_the_train_price_is_not_fetched_when_no_helsinki_option_survives(): void
    {
        $flights = new InMemoryFlightPriceSource();
        $flights->give(Airport::Ams, Airport::Tku, [self::throughTicket(Carrier::Sas, 150)]);

        $train = new FixedTrainPriceSource(Money::fromEuros(22.50));

        $this->handler($flights, $train)(
            new SearchFlightsQuery(new \DateTimeImmutable('2026-10-29'), Direction::NlToFi, 'chris'),
        );

        self::assertSame(0, $train->callCount, 'geen HEL-optie, dus geen apify-tegoed verbruiken');
    }

    public function test_the_train_price_is_fetched_only_once(): void
    {
        $flights = new InMemoryFlightPriceSource();
        $flights->give(Airport::Ams, Airport::Hel, [
            self::viaHel(Carrier::Finnair, 134, '13:00', '16:45'),
            self::viaHel(Carrier::Finnair, 145, '14:00', '17:45'),
        ]);

        $train = new FixedTrainPriceSource(Money::fromEuros(22.50));

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

        $this->handler($flights, new FixedTrainPriceSource(Money::zero()))(
            new SearchFlightsQuery(new \DateTimeImmutable('2026-10-25'), Direction::FiToNl, 'partner'),
        );

        self::assertSame(
            [[Airport::Hel, Airport::Ams, '2026-10-25'], [Airport::Tku, Airport::Ams, '2026-10-25']],
            $flights->calls,
        );
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
```

- [ ] **Step 2: Draai de test en zie hem falen**

Run: `docker compose run --rm php bin/phpunit tests/Application/SearchFlights/SearchFlightsHandlerTest.php`
Expected: FAIL — `Class "App\Application\SearchFlights\SearchFlightsHandler" not found`.

- [ ] **Step 3: Implementeer query, resultaat en gepriijsde optie**

Maak `api/src/Application/SearchFlights/SearchFlightsQuery.php`:

```php
<?php

declare(strict_types=1);

namespace App\Application\SearchFlights;

use App\Domain\Flight\Direction;

final readonly class SearchFlightsQuery
{
    public function __construct(
        public \DateTimeImmutable $date,
        public Direction $direction,
        public string $travellerId,
    ) {
    }
}
```

Maak `api/src/Application/SearchFlights/PricedItinerary.php`:

```php
<?php

declare(strict_types=1);

namespace App\Application\SearchFlights;

use App\Domain\Flight\Itinerary;
use App\Domain\Pricing\TotalCost;

final readonly class PricedItinerary
{
    public function __construct(
        public Itinerary $itinerary,
        public TotalCost $cost,
    ) {
    }
}
```

Maak `api/src/Application/SearchFlights/SearchResult.php`:

```php
<?php

declare(strict_types=1);

namespace App\Application\SearchFlights;

use App\Domain\Traveller\Traveller;

final readonly class SearchResult
{
    /**
     * @param PricedItinerary[] $options goedkoopste eerst
     */
    public function __construct(
        public array $options,
        public Traveller $traveller,
        public \DateTimeImmutable $searchedAt,
    ) {
    }
}
```

- [ ] **Step 4: Implementeer de handler**

Maak `api/src/Application/SearchFlights/SearchFlightsHandler.php`:

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
    public function __construct(
        private FlightPriceSource $flights,
        private TrainPriceSource $trains,
        private TotalCostCalculator $calculator,
        private PriceHistory $history,
    ) {
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

        usort(
            $options,
            static fn (PricedItinerary $a, PricedItinerary $b): int
                => $a->cost->total()->cents <=> $b->cost->total()->cents,
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

- [ ] **Step 5: Draai de test**

Run: `docker compose run --rm php bin/phpunit tests/Application/SearchFlights/SearchFlightsHandlerTest.php`
Expected: PASS, 6 tests.

- [ ] **Step 6: Commit**

```bash
git add api/src/Application api/tests/Application
git commit -m "feat: zoek-use-case die beide reispatronen samenvoegt"
```

---

### Task 13: HTTP-endpoint GET /api/search

**Files:**
- Create: `api/src/Infrastructure/Http/SearchController.php`, `api/src/Infrastructure/Http/SearchResultPresenter.php`
- Test: `api/tests/Infrastructure/Http/SearchControllerTest.php`
- Modify: `api/config/services.yaml`, `api/config/routes.yaml`

**Interfaces:**
- Consumes: `SearchFlightsHandler`, `SearchFlightsQuery`, `SearchResult` (Taak 12)
- Produces: `GET /api/search?date=YYYY-MM-DD&direction=nl-fi|fi-nl&traveller=chris|partner`

JSON-vorm per optie:

```json
{
  "carrier": "AY",
  "carrierLabel": "Finnair",
  "pattern": "via_hel_train",
  "departAt": "2026-10-29T13:00:00+00:00",
  "arriveAt": "2026-10-29T16:45:00+00:00",
  "stops": 0,
  "via": null,
  "flightNumbers": ["AY842"],
  "cost": {
    "basePrice": 13400,
    "baggageCost": 0,
    "trainCost": 2250,
    "total": 15650,
    "totalFormatted": "€ 156,50",
    "indicative": false
  }
}
```

- [ ] **Step 1: Schrijf de falende test**

Maak `api/tests/Infrastructure/Http/SearchControllerTest.php`:

```php
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

final class SearchControllerTest extends WebTestCase
{
    public function test_it_returns_priced_options_as_json(): void
    {
        $client = static::createClient();

        $flights = new InMemoryFlightPriceSource();
        $flights->give(Airport::Ams, Airport::Hel, [self::aFinnairFlight()]);
        static::getContainer()->set(FlightPriceSource::class, $flights);

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
```

- [ ] **Step 2: Draai de test en zie hem falen**

Run: `docker compose run --rm php bin/phpunit tests/Infrastructure/Http/SearchControllerTest.php`
Expected: FAIL — 404, de route bestaat nog niet.

- [ ] **Step 3: Installeer wat de controller nodig heeft**

```bash
docker compose run --rm php composer require symfony/framework-bundle symfony/routing --no-interaction
```

- [ ] **Step 4: Implementeer de presenter**

Maak `api/src/Infrastructure/Http/SearchResultPresenter.php`:

```php
<?php

declare(strict_types=1);

namespace App\Infrastructure\Http;

use App\Application\SearchFlights\PricedItinerary;
use App\Application\SearchFlights\SearchResult;
use App\Domain\Flight\Segment;

final readonly class SearchResultPresenter
{
    /**
     * @return array<string, mixed>
     */
    public function present(SearchResult $result): array
    {
        return [
            'traveller' => [
                'id' => $result->traveller->id,
                'name' => $result->traveller->name,
            ],
            'searchedAt' => $result->searchedAt->format(\DATE_ATOM),
            'options' => array_map($this->option(...), $result->options),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function option(PricedItinerary $priced): array
    {
        $itinerary = $priced->itinerary;
        $cost = $priced->cost;

        return [
            'carrier' => $itinerary->carrier->value,
            'carrierLabel' => $itinerary->carrier->label(),
            'pattern' => $itinerary->pattern->value,
            'departAt' => $itinerary->departAt()->format(\DATE_ATOM),
            'arriveAt' => $itinerary->arriveAt()->format(\DATE_ATOM),
            'stops' => $itinerary->stopCount(),
            'via' => $itinerary->connectsVia()?->value,
            'connectionMinutes' => $itinerary->connectionMinutes(),
            'flightNumbers' => array_map(
                static fn (Segment $s): string => $s->flightNumber,
                $itinerary->segments,
            ),
            'cost' => [
                'basePrice' => $cost->basePrice->cents,
                'baggageCost' => $cost->baggageCost->cents,
                'trainCost' => $cost->trainCost->cents,
                'total' => $cost->total()->cents,
                'totalFormatted' => $cost->total()->format(),
                'indicative' => $cost->indicative,
            ],
        ];
    }
}
```

- [ ] **Step 5: Implementeer de controller**

Maak `api/src/Infrastructure/Http/SearchController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Infrastructure\Http;

use App\Application\SearchFlights\SearchFlightsHandler;
use App\Application\SearchFlights\SearchFlightsQuery;
use App\Domain\Flight\Direction;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

#[AsController]
final readonly class SearchController
{
    public function __construct(
        private SearchFlightsHandler $handler,
        private SearchResultPresenter $presenter,
    ) {
    }

    #[Route('/api/search', methods: ['GET'])]
    public function __invoke(Request $request): JsonResponse
    {
        $rawDate = (string) $request->query->get('date', '');
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $rawDate);

        if (false === $date) {
            return new JsonResponse(['error' => 'Geef een geldige datum als YYYY-MM-DD.'], 400);
        }

        $direction = Direction::tryFrom((string) $request->query->get('direction', ''));

        if (null === $direction) {
            return new JsonResponse(['error' => 'Richting moet "nl-fi" of "fi-nl" zijn.'], 400);
        }

        try {
            $result = ($this->handler)(new SearchFlightsQuery(
                $date,
                $direction,
                (string) $request->query->get('traveller', ''),
            ));
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 400);
        }

        return new JsonResponse($this->presenter->present($result));
    }
}
```

- [ ] **Step 6: Bedraad routes en de fare-tarieven**

Voeg toe aan `api/config/routes.yaml`:

```yaml
controllers:
    resource:
        path: ../src/Infrastructure/Http/
        namespace: App\Infrastructure\Http
    type: attribute
```

Voeg toe aan `api/config/services.yaml` onder `services:`:

```yaml
    App\Domain\Pricing\FareUpgradeRates:
        factory: ['App\Domain\Pricing\FareUpgradeRates', 'fromConfig']
        arguments:
            $raw:
                AY: '%env(float:FARE_UPGRADE_AY)%'
                KL: '%env(float:FARE_UPGRADE_KL)%'
```

Voeg toe aan `api/.env`:

```
FARE_UPGRADE_AY=16
FARE_UPGRADE_KL=25
```

Finnair €16 is een vast, bekend tarief. KLM €25 is een schatting omdat KLM
dynamisch prijst — vandaar de `indicative`-markering op KLM-uitkomsten.

De tests in Taak 5, 6 en 8 gebruiken bewust **andere** bedragen (€35 en €40).
Dat is geen slordigheid: zo bewijzen ze dat de tarieven echt uit configuratie
komen en nergens hardgecodeerd zijn.

- [ ] **Step 7: Draai de test**

Run: `docker compose run --rm php bin/phpunit tests/Infrastructure/Http/SearchControllerTest.php`
Expected: PASS, 5 tests.

- [ ] **Step 8: Schrijf de falende test voor de geplande reizen**

De spec (§7) vraagt om `GET /api/trips` met de geplande reizen, in fase 1
hardcoded. Fase 4 vervangt de bron door Google Calendar.

Maak `api/tests/Infrastructure/Http/TripsControllerTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Http;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class TripsControllerTest extends WebTestCase
{
    public function test_it_lists_the_two_planned_trips(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/trips');

        self::assertResponseIsSuccessful();

        $body = json_decode($client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame([
            ['date' => '2026-10-25', 'direction' => 'fi-nl', 'traveller' => 'partner'],
            ['date' => '2026-10-29', 'direction' => 'nl-fi', 'traveller' => 'partner'],
        ], $body['trips']);
    }

    public function test_the_trips_are_sorted_by_date(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/trips');

        $body = json_decode($client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $dates = array_column($body['trips'], 'date');

        $sorted = $dates;
        sort($sorted);

        self::assertSame($sorted, $dates);
    }
}
```

- [ ] **Step 9: Draai de test en zie hem falen**

Run: `docker compose run --rm php bin/phpunit tests/Infrastructure/Http/TripsControllerTest.php`
Expected: FAIL — 404.

- [ ] **Step 10: Implementeer de geplande reizen**

Maak `api/src/Domain/Trip/PlannedTrip.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domain\Trip;

use App\Domain\Flight\Direction;

final readonly class PlannedTrip
{
    public function __construct(
        public \DateTimeImmutable $date,
        public Direction $direction,
        public string $travellerId,
    ) {
    }
}
```

Maak `api/src/Domain/Trip/PlannedTrips.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domain\Trip;

use App\Domain\Flight\Direction;

/**
 * Fase 1 houdt de planning hier vast. Fase 4 vervangt dit door Google Calendar;
 * alleen deze klasse hoeft dan te wijzigen.
 */
final readonly class PlannedTrips
{
    private const TRIPS = [
        ['2026-10-25', 'fi-nl', 'partner'],
        ['2026-10-29', 'nl-fi', 'partner'],
    ];

    /**
     * @return PlannedTrip[] op datum gesorteerd
     */
    public function all(): array
    {
        $trips = array_map(
            static fn (array $row): PlannedTrip => new PlannedTrip(
                new \DateTimeImmutable($row[0]),
                Direction::from($row[1]),
                $row[2],
            ),
            self::TRIPS,
        );

        usort($trips, static fn (PlannedTrip $a, PlannedTrip $b): int => $a->date <=> $b->date);

        return $trips;
    }
}
```

- [ ] **Step 11: Implementeer de controller**

Maak `api/src/Infrastructure/Http/TripsController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Infrastructure\Http;

use App\Domain\Trip\PlannedTrip;
use App\Domain\Trip\PlannedTrips;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

#[AsController]
final readonly class TripsController
{
    public function __construct(private PlannedTrips $trips)
    {
    }

    #[Route('/api/trips', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        return new JsonResponse([
            'trips' => array_map(
                static fn (PlannedTrip $t): array => [
                    'date' => $t->date->format('Y-m-d'),
                    'direction' => $t->direction->value,
                    'traveller' => $t->travellerId,
                ],
                $this->trips->all(),
            ),
        ]);
    }
}
```

- [ ] **Step 12: Draai beide controllertests**

Run: `docker compose run --rm php bin/phpunit tests/Infrastructure/Http`
Expected: PASS, 7 tests.

- [ ] **Step 13: Commit**

```bash
git add api/src/Infrastructure/Http api/src/Domain/Trip api/tests/Infrastructure/Http api/config
git commit -m "feat: zoek-endpoint en geplande reizen"
```

---

### Task 14: Vue-dashboard

**Files:**
- Create: `web/package.json`, `web/vite.config.ts`, `web/tsconfig.json`, `web/index.html`
- Create: `web/src/main.ts`, `web/src/App.vue`, `web/src/api.ts`, `web/src/types.ts`
- Create: `web/src/components/OptionCard.vue`
- Test: `web/src/components/__tests__/OptionCard.spec.ts`

**Interfaces:**
- Consumes: `GET /api/search` (Taak 13)
- Produces: draaiende SPA op `http://localhost:8080`

Elk resultaat toont de totaalprijs met de opbouw eronder — vluchtprijs, bagage,
trein — zodat zichtbaar is wáárom een optie wint. Opties met `indicative: true`
krijgen een zichtbare markering.

- [ ] **Step 1: Maak de projectbestanden**

`web/package.json`:

```json
{
  "name": "vluchtzoeker-web",
  "private": true,
  "type": "module",
  "scripts": {
    "dev": "vite",
    "build": "vue-tsc -b && vite build",
    "test": "vitest run"
  },
  "dependencies": {
    "vue": "^3.5.0"
  },
  "devDependencies": {
    "@vitejs/plugin-vue": "^5.2.0",
    "@vue/test-utils": "^2.4.6",
    "happy-dom": "^15.11.0",
    "typescript": "^5.7.0",
    "vite": "^6.0.0",
    "vitest": "^2.1.0",
    "vue-tsc": "^2.1.0"
  }
}
```

`web/vite.config.ts`:

```ts
import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'

export default defineConfig({
  plugins: [vue()],
  server: {
    host: '0.0.0.0',
    port: 5173,
    proxy: {
      '/api': 'http://nginx:80',
    },
  },
  test: {
    environment: 'happy-dom',
  },
})
```

`web/tsconfig.json`:

```json
{
  "compilerOptions": {
    "target": "ES2022",
    "module": "ESNext",
    "moduleResolution": "bundler",
    "strict": true,
    "jsx": "preserve",
    "types": ["vitest/globals"],
    "noEmit": true,
    "skipLibCheck": true
  },
  "include": ["src/**/*.ts", "src/**/*.vue"]
}
```

`web/index.html`:

```html
<!DOCTYPE html>
<html lang="nl">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Leiden ↔ Turku vluchtzoeker</title>
  </head>
  <body>
    <div id="app"></div>
    <script type="module" src="/src/main.ts"></script>
  </body>
</html>
```

- [ ] **Step 2: Maak de types**

`web/src/types.ts`:

```ts
export type Pattern = 'via_hel_train' | 'through'
export type Direction = 'nl-fi' | 'fi-nl'
export type TravellerId = 'chris' | 'partner'

export interface Cost {
  basePrice: number
  baggageCost: number
  trainCost: number
  total: number
  totalFormatted: string
  indicative: boolean
}

export interface Option {
  carrier: string
  carrierLabel: string
  pattern: Pattern
  departAt: string
  arriveAt: string
  stops: number
  via: string | null
  connectionMinutes: number | null
  flightNumbers: string[]
  cost: Cost
}

export interface SearchResult {
  traveller: { id: string; name: string }
  searchedAt: string
  options: Option[]
}

export interface Trip {
  date: string
  direction: Direction
  traveller: TravellerId
}
```

- [ ] **Step 3: Schrijf de falende componenttest**

Maak `web/src/components/__tests__/OptionCard.spec.ts`:

```ts
import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import OptionCard from '../OptionCard.vue'
import type { Option } from '../../types'

const viaHelsinki: Option = {
  carrier: 'AY',
  carrierLabel: 'Finnair',
  pattern: 'via_hel_train',
  departAt: '2026-10-29T13:00:00+00:00',
  arriveAt: '2026-10-29T16:45:00+00:00',
  stops: 0,
  via: null,
  connectionMinutes: null,
  flightNumbers: ['AY842'],
  cost: {
    basePrice: 13400,
    baggageCost: 0,
    trainCost: 2250,
    total: 15650,
    totalFormatted: '€ 156,50',
    indicative: false,
  },
}

describe('OptionCard', () => {
  it('toont de totaalprijs', () => {
    const card = mount(OptionCard, { props: { option: viaHelsinki } })

    expect(card.text()).toContain('€ 156,50')
    expect(card.text()).toContain('Finnair')
  })

  it('splitst de prijs uit zodat zichtbaar is waarom een optie wint', () => {
    const card = mount(OptionCard, { props: { option: viaHelsinki } })
    const rows = card.findAll('[data-testid="cost-row"]')

    expect(rows).toHaveLength(3)
    expect(rows[0].text()).toContain('Vlucht')
    expect(rows[1].text()).toContain('Bagage')
    expect(rows[2].text()).toContain('Trein')
  })

  it('laat de treinregel weg bij een doorgaand ticket', () => {
    const through: Option = {
      ...viaHelsinki,
      pattern: 'through',
      cost: { ...viaHelsinki.cost, trainCost: 0, total: 13400, totalFormatted: '€ 134,00' },
    }

    const card = mount(OptionCard, { props: { option: through } })

    expect(card.findAll('[data-testid="cost-row"]')).toHaveLength(2)
  })

  it('markeert een indicatieve prijs', () => {
    const klm: Option = {
      ...viaHelsinki,
      carrier: 'KL',
      carrierLabel: 'KLM',
      cost: { ...viaHelsinki.cost, baggageCost: 4000, indicative: true },
    }

    const card = mount(OptionCard, { props: { option: klm } })

    expect(card.find('[data-testid="indicative"]').exists()).toBe(true)
    expect(card.text()).toContain('indicatief')
  })

  it('markeert een vaste prijs niet', () => {
    const card = mount(OptionCard, { props: { option: viaHelsinki } })

    expect(card.find('[data-testid="indicative"]').exists()).toBe(false)
  })

  it('toont de overstap als die er is', () => {
    const through: Option = {
      ...viaHelsinki,
      carrier: 'SK',
      carrierLabel: 'SAS',
      pattern: 'through',
      stops: 1,
      via: 'ARN',
      connectionMinutes: 90,
      flightNumbers: ['SK552', 'SK721'],
    }

    const card = mount(OptionCard, { props: { option: through } })

    expect(card.text()).toContain('ARN')
    expect(card.text()).toContain('90 min')
  })
})
```

- [ ] **Step 4: Draai de test en zie hem falen**

```bash
docker compose run --rm node sh -c "npm install && npm run test"
```

Expected: FAIL — `Failed to resolve import "../OptionCard.vue"`.

- [ ] **Step 5: Implementeer OptionCard**

Maak `web/src/components/OptionCard.vue`:

```vue
<script setup lang="ts">
import { computed } from 'vue'
import type { Option } from '../types'

const props = defineProps<{ option: Option }>()

const euro = (cents: number) => `€ ${(cents / 100).toFixed(2).replace('.', ',')}`

const time = (iso: string) =>
  new Date(iso).toLocaleTimeString('nl-NL', { hour: '2-digit', minute: '2-digit' })

const rows = computed(() => {
  const r = [
    { label: 'Vlucht', cents: props.option.cost.basePrice },
    { label: 'Bagage', cents: props.option.cost.baggageCost },
  ]

  if (props.option.pattern === 'via_hel_train') {
    r.push({ label: 'Trein HEL–TKU', cents: props.option.cost.trainCost })
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
        <dd>{{ euro(row.cents) }}</dd>
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

- [ ] **Step 6: Draai de test**

```bash
docker compose run --rm node npm run test
```

Expected: PASS, 6 tests.

- [ ] **Step 7: Implementeer de API-laag en het scherm**

`web/src/api.ts`:

```ts
import type { Direction, SearchResult, Trip, TravellerId } from './types'

export async function search(
  date: string,
  direction: Direction,
  traveller: TravellerId,
): Promise<SearchResult> {
  const params = new URLSearchParams({ date, direction, traveller })
  const response = await fetch(`/api/search?${params}`)

  if (!response.ok) {
    const body = await response.json().catch(() => ({ error: 'Onbekende fout' }))
    throw new Error(body.error ?? `Zoeken mislukte (${response.status})`)
  }

  return response.json()
}

export async function plannedTrips(): Promise<Trip[]> {
  const response = await fetch('/api/trips')

  if (!response.ok) {
    return []
  }

  const body = await response.json()

  return body.trips
}
```

`web/src/App.vue`:

```vue
<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { plannedTrips, search } from './api'
import OptionCard from './components/OptionCard.vue'
import type { Direction, SearchResult, Trip, TravellerId } from './types'

const date = ref('2026-10-29')
const direction = ref<Direction>('nl-fi')
const traveller = ref<TravellerId>('partner')

const trips = ref<Trip[]>([])
const result = ref<SearchResult | null>(null)
const error = ref('')
const loading = ref(false)

onMounted(async () => {
  trips.value = await plannedTrips()
})

function pick(trip: Trip) {
  date.value = trip.date
  direction.value = trip.direction
  traveller.value = trip.traveller
}

const label = (trip: Trip) =>
  `${new Date(trip.date).toLocaleDateString('nl-NL', { day: 'numeric', month: 'short' })} ` +
  `${trip.direction === 'nl-fi' ? '→ Finland' : '→ Nederland'}`

async function run() {
  loading.value = true
  error.value = ''

  try {
    result.value = await search(date.value, direction.value, traveller.value)
  } catch (e) {
    error.value = e instanceof Error ? e.message : String(e)
    result.value = null
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <main>
    <h1>Leiden ↔ Turku</h1>

    <nav v-if="trips.length > 0" class="trips">
      <span>Gepland:</span>
      <button v-for="trip in trips" :key="trip.date" type="button" @click="pick(trip)">
        {{ label(trip) }}
      </button>
    </nav>

    <form @submit.prevent="run">
      <input v-model="date" type="date" required />

      <select v-model="direction">
        <option value="nl-fi">Nederland → Finland</option>
        <option value="fi-nl">Finland → Nederland</option>
      </select>

      <select v-model="traveller">
        <option value="chris">Chris</option>
        <option value="partner">Partner</option>
      </select>

      <button type="submit" :disabled="loading">
        {{ loading ? 'Zoeken…' : 'Zoek' }}
      </button>
    </form>

    <p v-if="error" class="error">{{ error }}</p>

    <template v-if="result">
      <p v-if="result.options.length === 0">
        Geen opties die aan de regels voldoen op deze datum.
      </p>
      <OptionCard
        v-for="(option, i) in result.options"
        :key="i"
        :option="option"
      />
    </template>
  </main>
</template>

<style>
body { font-family: system-ui, sans-serif; margin: 0; background: #fafafa; }
main { max-width: 42rem; margin: 0 auto; padding: 1.5rem; }
form { display: flex; gap: .5rem; flex-wrap: wrap; margin-bottom: 1.5rem; }
input, select, button { padding: .5rem; font: inherit; }
.trips { display: flex; gap: .5rem; align-items: center; flex-wrap: wrap; margin-bottom: .75rem; }
.trips span { color: #666; font-size: .9rem; }
.trips button { background: #eef3ff; border: 1px solid #c7d7ff; border-radius: 999px; cursor: pointer; }
.error { color: #b00020; }
</style>
```

`web/src/main.ts`:

```ts
import { createApp } from 'vue'
import App from './App.vue'

createApp(App).mount('#app')
```

- [ ] **Step 8: Controleer dat de app draait**

```bash
docker compose up -d
docker compose run --rm node npm run build
```

Open `http://localhost:8080`. Expected: het formulier verschijnt.

- [ ] **Step 9: Commit**

```bash
git add web
git commit -m "feat: vue-dashboard met uitgesplitste totaalprijs"
```

---

### Task 15: Domeinzuiverheid bewaken en productie-opstelling

**Files:**
- Create: `api/tests/Architecture/DomainPurityTest.php`
- Create: `compose.prod.yaml`, `docker/nginx/prod.conf`
- Modify: `README.md`

**Interfaces:**
- Consumes: de hele `src/Domain`-boom
- Produces: een test die faalt zodra iemand een framework-import in het domein zet

De global constraint "`src/Domain/**` mag geen framework-imports bevatten" is
alleen echt een regel als hij afgedwongen wordt. Anders sluipt de eerste
`use Symfony\...` er binnen een maand in.

- [ ] **Step 1: Schrijf de falende test**

Maak `api/tests/Architecture/DomainPurityTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use PHPUnit\Framework\TestCase;

final class DomainPurityTest extends TestCase
{
    private const FORBIDDEN = ['Symfony\\', 'Doctrine\\', 'Psr\\', 'Twig\\'];

    public function test_the_domain_layer_imports_no_framework_code(): void
    {
        $offenders = [];

        foreach ($this->domainFiles() as $file) {
            $source = file_get_contents($file);

            foreach (self::FORBIDDEN as $prefix) {
                if (str_contains($source, 'use '.$prefix)) {
                    $offenders[] = sprintf('%s importeert %s', basename($file), $prefix);
                }
            }
        }

        self::assertSame([], $offenders, implode("\n", $offenders));
    }

    public function test_every_domain_file_is_strictly_typed(): void
    {
        $offenders = [];

        foreach ($this->domainFiles() as $file) {
            if (!str_contains(file_get_contents($file), 'declare(strict_types=1)')) {
                $offenders[] = basename($file);
            }
        }

        self::assertSame([], $offenders, 'ontbrekende strict_types: '.implode(', ', $offenders));
    }

    public function test_the_domain_directory_is_not_empty(): void
    {
        self::assertNotEmpty($this->domainFiles(), 'de test zou anders altijd slagen');
    }

    /**
     * @return string[]
     */
    private function domainFiles(): array
    {
        $files = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(__DIR__.'/../../src/Domain'),
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && 'php' === $file->getExtension()) {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
```

- [ ] **Step 2: Draai de test**

Run: `docker compose run --rm php bin/phpunit tests/Architecture/DomainPurityTest.php`
Expected: PASS, 3 tests. Slaagt hij níét, verplaats dan de betreffende klasse naar `Infrastructure`.

- [ ] **Step 3: Maak de productie-nginx**

Maak `docker/nginx/prod.conf`:

```nginx
server {
    listen 80;
    root /app/public;

    location /api {
        try_files $uri /index.php$is_args$args;
    }

    location ~ ^/index\.php(/|$) {
        fastcgi_pass php:9000;
        fastcgi_split_path_info ^(.+\.php)(/.*)$;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_param DOCUMENT_ROOT $document_root;
        internal;
    }

    location / {
        root /srv/web;
        try_files $uri $uri/ /index.html;
    }
}
```

- [ ] **Step 4: Maak de productie-override**

Maak `compose.prod.yaml`:

```yaml
services:
  php:
    environment:
      APP_ENV: prod
      APP_DEBUG: '0'

  nginx:
    volumes:
      - ./api:/app
      - ./web/dist:/srv/web:ro
      - ./docker/nginx/prod.conf:/etc/nginx/conf.d/default.conf:ro
    depends_on:
      - php

  node:
    profiles: ['dev']
```

Het `profiles: ['dev']`-blok zorgt dat de Vite-container op de VPS niet
meedraait; daar serveert nginx de gebouwde bestanden uit `web/dist`.

- [ ] **Step 5: Schrijf de README**

Maak `README.md`:

````markdown
# Leiden ↔ Turku vluchtzoeker

Zoekt de goedkoopste reisopties tussen Leiden en Turku, met een totaalprijs
die de VR-trein en de bagagekosten per reiziger meerekent.

- [Ontwerp](docs/superpowers/specs/2026-09-16-leiden-turku-vluchtzoeker-design.md)
- [Implementatieplan fase 1](docs/superpowers/plans/2026-09-16-vluchtzoeker-fase-1.md)

## Instellen

Kopieer `api/.env` naar `api/.env.local` en vul in:

| Variabele | Waar vandaan |
|---|---|
| `BRIGHTDATA_TOKEN` | brightdata.com — 5.000 gratis credits per maand |
| `BRIGHTDATA_ZONE` | naam van je SERP-zone |
| `APIFY_TOKEN` | apify.com — $5 gratis tegoed per maand |
| `FARE_UPGRADE_AY` | Finnair Light→Plus in euro's — **16** (vast tarief) |
| `FARE_UPGRADE_KL` | KLM Light→Plus in euro's — **25** (schatting; dynamisch geprijsd) |

De app **start niet** zonder `FARE_UPGRADE_AY` en `FARE_UPGRADE_KL`. Dat is
opzet: doorrekenen met €0 zou Light-tarieven structureel te goedkoop laten lijken.

## Lokaal draaien

```bash
docker compose up
```

Open http://localhost:8080

## Testen

```bash
docker compose run --rm php bin/phpunit
docker compose run --rm node npm run test
```

## Naar de VPS

```bash
docker compose run --rm node npm run build
docker compose -f compose.yaml -f compose.prod.yaml up -d
```
````

- [ ] **Step 6: Draai de volledige suite**

```bash
docker compose run --rm php bin/phpunit
docker compose run --rm node npm run test
```

Expected: alles groen. PHP: 50+ tests. JS: 6 tests.

- [ ] **Step 7: Commit**

```bash
git add -A
git commit -m "feat: domeinzuiverheidstest, productie-opstelling en readme"
```

---

## Wat fase 1 niet doet

Bewust buiten scope, conform de spec:

- Geen prijsgeschiedenis (fase 2 — de poort staat klaar, `NullPriceHistory` vervangen)
- Geen meldingen of baselines (fase 3)
- Geen Google Calendar (fase 4)
- Geen Playwright e2e (fase 2)
- Geen ±1 dag-suggesties — de regels staan er, het zoeken over meerdere datums niet
- Geen Travelpayouts-bron — pas nodig zodra baselines gebouwd worden
