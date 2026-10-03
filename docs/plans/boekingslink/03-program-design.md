# Program Design: Boeken-knop per vlucht

## Files

### Backend (`api/`)
| Bestand | Nieuw/gewijzigd | Waarom hier |
|---|---|---|
| `src/Domain/Flight/Itinerary.php` | gewijzigd | Laatste ctor-param `?string $bookingRef = null`; de 24 bestaande `new Itinerary(...)`-aanroepen blijven ongewijzigd. |
| `src/Domain/Booking/BookingLink.php` | nieuw | Uitkomst "directe link" of "geen directe link"; framework-vrij. |
| `src/Domain/Booking/BookingLinkUnavailable.php` | nieuw | Exception voor storing / onleesbaar antwoord — onderscheidt "geen link" van "kon niet vragen". |
| `src/Domain/Port/BookingLinkSource.php` | nieuw | Poort, naast `FlightPriceSource`. |
| `src/Infrastructure/Source/IgnavFlightSource.php` | gewijzigd | Leest `ignav_id` en geeft hem als `bookingRef` aan `Itinerary`. |
| `src/Infrastructure/Source/IgnavBookingLinkSource.php` | nieuw | ignav-adapter: POST, kies directe link, cache. |
| `src/Infrastructure/Http/SearchResultPresenter.php` | gewijzigd | `'bookingRef' => $itinerary->bookingRef` per optie. |
| `src/Infrastructure/Http/BookingLinkController.php` | nieuw | `GET /api/booking-link`; validatie + statuscodes. |
| `config/services.yaml` | gewijzigd | Bedrading adapter (`$apiKey`, `$cache: '@cache.app'`, `$logger`) + alias poort; `when@test` publieke alias. |
| `tests/Infrastructure/Source/fixtures/ignav-booking-links.json` | nieuw | **Echt gemeten** respons (slice 2, vóór de adapter). |
| `tests/Infrastructure/Source/IgnavBookingLinkSourceTest.php` | nieuw | Adaptertests tegen de fixture. |
| `tests/Infrastructure/Source/BookingLinkSourceWiringTest.php` | nieuw | Container-bedrading, zoals `FlightSourceWiringTest`. |
| `tests/Infrastructure/Http/BookingLinkControllerTest.php` | nieuw | `WebTestCase` door de echte container. |
| `tests/Double/FixedBookingLinkSource.php` | nieuw | Testdubbel met `$calls`-teller, zoals `FixedTrainPriceSource`. |
| `tests/Infrastructure/Source/IgnavFlightSourceTest.php`, `tests/Infrastructure/Http/SearchControllerTest.php` | gewijzigd | `bookingRef` door adapter en presenter. |

### Frontend (`web/`)
| Bestand | Nieuw/gewijzigd | Waarom hier |
|---|---|---|
| `src/types.ts` | gewijzigd | `Option.bookingRef: string \| null`; `BookingLinkResult`. |
| `src/booking.ts` | nieuw | Pure functies: URL naar `boeken.html`, homepage per maatschappij. Testbaar zonder DOM. |
| `src/api.ts` | gewijzigd | `fetchBookingLink()`. |
| `src/components/OptionCard.vue` | gewijzigd | Knop "Boeken ↗" met `@click.stop` **en** `@keydown.stop` (anders selecteert Enter/spatie op de knop via de `article` alsnog de regel). |
| `boeken.html` | nieuw | Tweede Vite-entry, naast `index.html`. |
| `src/boeken.ts` | nieuw | Kale TS voor het nieuwe tabblad; logica in `runBookingPage()` met geïnjecteerde afhankelijkheden. |
| `vite.config.ts` | gewijzigd | `build.rollupOptions.input = { main: 'index.html', boeken: 'boeken.html' }`. Prod-nginx (`try_files $uri`) serveert hem zonder wijziging. |
| `src/__tests__/fixtures.ts` | gewijzigd | `makeOption` krijgt `bookingRef`. |
| `src/__tests__/booking.spec.ts`, `src/__tests__/boeken.spec.ts` | nieuw | |
| `src/components/__tests__/OptionCard.spec.ts` | gewijzigd | Knopgedrag. |

## Types & signatures

```php
// src/Domain/Flight/Itinerary.php
public function __construct(
    public Carrier $carrier,
    public array $segments,
    public Money $basePrice,
    public RoutePattern $pattern,
    /** Verwijzing van de bron om later een boekingslink op te vragen (ignav_id). */
    public ?string $bookingRef = null,
)

// src/Domain/Booking/BookingLink.php
final readonly class BookingLink
{
    private function __construct(public ?string $url, public ?string $provider) {}
    public static function direct(string $url, string $provider): self; // gooit bij niet-https url
    public static function noDirectLink(): self;
    public function isDirect(): bool;
}

// src/Domain/Booking/BookingLinkUnavailable.php
final class BookingLinkUnavailable extends \RuntimeException {}

// src/Domain/Port/BookingLinkSource.php
interface BookingLinkSource
{
    /** @throws BookingLinkUnavailable bij storing, onleesbaar of onverwacht antwoord */
    public function linkFor(string $bookingRef): BookingLink;
}

// src/Infrastructure/Source/IgnavBookingLinkSource.php
final readonly class IgnavBookingLinkSource implements BookingLinkSource
{
    private const ENDPOINT = 'https://ignav.com/api/fares/booking-links';
    private const TTL_SECONDS = 1800;
    private const NO_DIRECT_SENTINEL = '__no_direct_link__';
    /** Gemeten 2026-10-03: maatschappij zelf = "airline", reisbureau = "third_party". */
    private const DIRECT_PROVIDER_TYPE = 'airline';

    public function __construct(
        private HttpClientInterface $http,
        private CacheItemPoolInterface $cache,
        private string $apiKey,
        private ?LoggerInterface $logger = null,
    ) {}

    public function linkFor(string $bookingRef): BookingLink;
    /** @param array<string,mixed> $payload */
    private function pickDirect(array $payload): BookingLink;
}
// Cache-sleutel: 'ignav.booking.'.$bookingRef
// Cache-waarde: ['url' => ..., 'provider' => ...] of NO_DIRECT_SENTINEL. Exceptions: niet cachen.

// src/Infrastructure/Http/BookingLinkController.php
#[Route('/api/booking-link', methods: ['GET'])]
public function __invoke(Request $request): JsonResponse
// ref: /^[0-9a-f]{32}$/ anders 400
// 200 {"status":"found","url":…,"provider":…} | 200 {"status":"no_direct_link"} | 502 {"error":…}
```

```ts
// src/types.ts
export interface Option { /* … bestaand … */ bookingRef: string | null }
export type BookingLinkResult =
  | { status: 'found'; url: string; provider: string }
  | { status: 'no_direct_link' }

// src/booking.ts
export const CARRIER_HOMEPAGES: Record<string, { label: string; url: string }>
  // AY → Finnair https://www.finnair.com · KL → KLM https://www.klm.nl · SK → SAS https://www.flysas.com
export function bookingPageUrl(option: Option): string | null
  // null als bookingRef ontbreekt; anders '/boeken.html?ref=…&carrier=…&flight=AY1302&date=2026-10-29'
  // date = vertrekdatum in de tijdzone van de vertrekluchthaven (timezoneFor)

// src/api.ts
export async function fetchBookingLink(ref: string): Promise<BookingLinkResult> // gooit Error bij !ok

// src/boeken.ts
export interface BookingPageDeps {
  search: string                                   // location.search
  render: (html: string) => void                   // zet de inhoud van #app
  fetchLink: typeof fetchBookingLink
  navigate: (url: string) => void                  // location.replace
}
export async function runBookingPage(deps: BookingPageDeps): Promise<void>
```

## Call stack

**Zoeken (gewijzigd, zelfde aantal calls):**
`SearchController` → `SearchFlightsHandler` → `IgnavFlightSource::search` →
`toItinerary` (leest `ignav_id`) → `new Itinerary(..., bookingRef)` →
`SearchResultPresenter::option` → `bookingRef` in JSON → `Option.bookingRef`.

**Boeken:**
1. `OptionCard` knop `@click.stop` → `bookingPageUrl(option)` →
   `window.open(url, '_blank', 'noopener')`.
2. Nieuw tabblad: `boeken.html` → `boeken.ts` → `runBookingPage(deps)`:
   `render('Boekingslink ophalen bij KLM…')` → `fetchBookingLink(ref)`.
3. `GET /api/booking-link` → `BookingLinkController` → valideer → 
   `BookingLinkSource::linkFor` → `IgnavBookingLinkSource`:
   cache-hit → terug; anders `POST ENDPOINT {ignav_id}` → `pickDirect`
   → `BookingLink` → cache → terug. `BookingLinkUnavailable` → 502.
4. `runBookingPage`: `found` → `navigate(url)`; `no_direct_link` →
   `render(tekst + link CARRIER_HOMEPAGES[carrier])`; Error → `render(foutmelding)`.
   Ontbrekende `ref`/`carrier` in de querystring → foutmelding, geen fetch.

## Test plan

### Backend
`IgnavFlightSourceTest`
- `test_it_carries_the_ignav_id_as_booking_ref` — echte fixture → Finnair-optie heeft het `ignav_id` uit de fixture.
- `test_a_missing_ignav_id_gives_a_null_booking_ref` — optie blijft bestaan, ref null.

`SearchControllerTest`
- `test_each_option_exposes_its_booking_ref` — door de container: `options[0].bookingRef` = ref uit de dubbel.

`IgnavBookingLinkSourceTest` (MockHttpClient + `SpyCacheItemPool`/ArrayAdapter, **echte fixture**)
- `test_it_posts_only_the_ignav_id_with_the_api_key` — géén `market` (gemeten: anders 400)
- `test_it_returns_the_airlines_own_link_from_the_measured_response`
- `test_ota_links_alone_give_no_direct_link`
- `test_the_cheapest_of_several_direct_links_wins`
- `test_a_non_https_url_is_not_used` — `javascript:`/`http:` → geen directe link
- `test_a_second_request_is_served_from_cache` — 2× `linkFor`, 1 HTTP-call
- `test_no_direct_link_is_cached_too` — 2×, 1 HTTP-call
- `test_a_failure_is_not_cached` — 500 dan 200 → 2 HTTP-calls, tweede geeft link
- `test_http_error_throws_unavailable` / `test_unreadable_json_throws_unavailable` / `test_missing_booking_options_throws_unavailable`

`BookingLinkSourceWiringTest` (KernelTestCase)
- `test_the_container_wires_ignav_as_booking_link_source`
- `test_it_uses_the_app_cache_pool` — de echte bedrading cachet: twee aanvragen via de container-service met een MockHttpClient → 1 call (vangt een vergeten `$cache`-argument).

`BookingLinkControllerTest` (WebTestCase, `FixedBookingLinkSource` in de container)
- `test_a_direct_link_is_returned_as_found`
- `test_no_direct_link_is_returned_as_such`
- `test_an_invalid_ref_is_400_and_never_reaches_the_source` — `$calls === 0`
- `test_an_unavailable_source_is_502`

### Frontend
`booking.spec.ts`
- `bookingPageUrl` bevat ref, carrier, vluchtnummers en datum (url-encoded)
- datum volgt de vertrekluchthaven: 23:30 UTC vanaf HEL → volgende dag
- `null` zonder `bookingRef`

`OptionCard.spec.ts`
- toont "Boeken" als `bookingRef` er is; geen knop zonder
- klik → `window.open` met `bookingPageUrl(option)`, `'_blank'`, `'noopener'`
- klik emit **geen** `select`
- Enter op de knop emit **geen** `select`

`boeken.spec.ts` (`runBookingPage` met nep-deps)
- toont eerst "Boekingslink ophalen bij KLM…"
- `found` → `navigate(url)`
- `no_direct_link` → tekst met maatschappij + link naar `CARRIER_HOMEPAGES`
- fetch gooit → foutmelding, geen navigate
- ontbrekende ref → foutmelding, fetch nooit aangeroepen

Elke test moet aantoonbaar falen zonder de bijbehorende code (mutatie per slice).

## Least confident decisions
1. **Alleen `provider_type === "airline"`** (herzien 2026-10-03 op voorstel van de
   eigenaar, na de meting). Geen naam-matching, geen `carrier` naar de backend.
   Restrisico: bij een gemengde vlucht (bijv. KL + SK) kan de airline-link van de
   ándere maatschappij zijn — nog steeds een officiële link, dus acceptabel.
   KLM/SAS zien we voor het eerst bij het bewijs van slice 2.
2. *(vervallen — de backend krijgt geen `carrier` meer)*
3. **Cache ook "geen directe link" 30 min.** Spaart tegoed, maar als ignav even
   geen airline-link had, zie je dat een half uur lang.
4. **Kale TS voor `boeken.html`** in plaats van Vue. Kleiner en sneller, maar een
   tweede stijl in de codebase.
5. **"Goedkoopste directe link"** bij meerdere: kan een tarief zonder bagage zijn
   terwijl je een duurder tarief wilt. We tonen de prijs niet, dus je kiest dan
   op de site van de maatschappij zelf.
6. **Verlopen `ignav_id`**: onbekend hoe ignav dat meldt (404? lege lijst?). Nu:
   alles wat geen geldige respons is → 502 "kon niet worden opgehaald".
