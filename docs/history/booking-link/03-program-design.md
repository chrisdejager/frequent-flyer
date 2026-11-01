# Program Design: Book button per flight

## Files

### Backend (`api/`)
| File | New/Changed | Why here |
|---|---|---|
| `src/Domain/Flight/Itinerary.php` | changed | Last ctor param `?string $bookingRef = null`; the 24 existing `new Itinerary(...)`-calls stay unchanged. |
| `src/Domain/Booking/BookingLink.php` | new | Outcome "direct link" or "no direct link"; framework-free. |
| `src/Domain/Booking/BookingLinkUnavailable.php` | new | Exception for outage / unreadable response — distinguishes "no link" from "couldn't ask". |
| `src/Domain/Port/BookingLinkSource.php` | new | Port, alongside `FlightPriceSource`. |
| `src/Infrastructure/Source/IgnavFlightSource.php` | changed | Now reads `ignav_id` and passes it as `bookingRef` to `Itinerary`. |
| `src/Infrastructure/Source/IgnavBookingLinkSource.php` | new | ignav adapter: POST, pick direct link, cache. |
| `src/Infrastructure/Http/SearchResultPresenter.php` | changed | `'bookingRef' => $itinerary->bookingRef` per option. |
| `src/Infrastructure/Http/BookingLinkController.php` | new | `GET /api/booking-link`; validation + status codes. |
| `config/services.yaml` | changed | Wiring adapter (`$apiKey`, `$cache: '@cache.app'`, `$logger`) + alias port; `when@test` public alias. |
| `tests/Infrastructure/Source/fixtures/ignav-booking-links.json` | new | **Real measured** response (slice 2, before adapter). |
| `tests/Infrastructure/Source/IgnavBookingLinkSourceTest.php` | new | Adapter tests against the fixture. |
| `tests/Infrastructure/Source/BookingLinkSourceWiringTest.php` | new | Container wiring, like `FlightSourceWiringTest`. |
| `tests/Infrastructure/Http/BookingLinkControllerTest.php` | new | `WebTestCase` through the real container. |
| `tests/Double/FixedBookingLinkSource.php` | new | Test double with `$calls` counter, like `FixedTrainPriceSource`. |
| `tests/Infrastructure/Source/IgnavFlightSourceTest.php`, `tests/Infrastructure/Http/SearchControllerTest.php` | changed | `bookingRef` through adapter and presenter. |

### Frontend (`web/`)
| File | New/Changed | Why here |
|---|---|---|
| `src/types.ts` | changed | `Option.bookingRef: string \| null`; `BookingLinkResult`. |
| `src/booking.ts` | new | Pure functions: URL to `book.html`, homepage per airline. Testable without DOM. |
| `src/api.ts` | changed | `fetchBookingLink()`. |
| `src/components/OptionCard.vue` | changed | "Book ↗" button with `@click.stop` **and** `@keydown.stop` (else Enter/space on the button still selects the row via the `article`). |
| `book.html` | new | Second Vite entry, alongside `index.html`. |
| `src/bookingPage.ts` | new | Plain TS for the new tab; logic in `runBookingPage()` with injected dependencies. |
| `vite.config.ts` | changed | `build.rollupOptions.input = { main: 'index.html', book: 'book.html' }`. Prod nginx (`try_files $uri`) serves it without change. |
| `src/__tests__/fixtures.ts` | changed | `makeOption` gets `bookingRef`. |
| `src/__tests__/booking.spec.ts`, `src/__tests__/bookingPage.spec.ts` | new | |
| `src/components/__tests__/OptionCard.spec.ts` | changed | Button behavior. |

## Types & signatures

```php
// src/Domain/Flight/Itinerary.php
public function __construct(
    public Carrier $carrier,
    public array $segments,
    public Money $basePrice,
    public RoutePattern $pattern,
    /** Reference from the source to later fetch a booking link (ignav_id). */
    public ?string $bookingRef = null,
)

// src/Domain/Booking/BookingLink.php
final readonly class BookingLink
{
    private function __construct(public ?string $url, public ?string $provider) {}
    public static function direct(string $url, string $provider): self; // throws on non-https url
    public static function noDirectLink(): self;
    public function isDirect(): bool;
}

// src/Domain/Booking/BookingLinkUnavailable.php
final class BookingLinkUnavailable extends \RuntimeException {}

// src/Domain/Port/BookingLinkSource.php
interface BookingLinkSource
{
    /** @throws BookingLinkUnavailable on outage, unreadable or unexpected response */
    public function linkFor(string $bookingRef): BookingLink;
}

// src/Infrastructure/Source/IgnavBookingLinkSource.php
final readonly class IgnavBookingLinkSource implements BookingLinkSource
{
    private const ENDPOINT = 'https://ignav.com/api/fares/booking-links';
    private const TTL_SECONDS = 1800;
    private const NO_DIRECT_SENTINEL = '__no_direct_link__';
    /** Measured 2026-10-03: airline itself = "airline", travel agency = "third_party". */
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
// Cache key: 'ignav.booking.'.$bookingRef
// Cache value: ['url' => ..., 'provider' => ...] or NO_DIRECT_SENTINEL. Exceptions: not cached.

// src/Infrastructure/Http/BookingLinkController.php
#[Route('/api/booking-link', methods: ['GET'])]
public function __invoke(Request $request): JsonResponse
// ref: /^[0-9a-f]{32}$/ else 400
// 200 {"status":"found","url":…,"provider":…} | 200 {"status":"no_direct_link"} | 502 {"error":…}
```

```ts
// src/types.ts
export interface Option { /* … existing … */ bookingRef: string | null }
export type BookingLinkResult =
  | { status: 'found'; url: string; provider: string }
  | { status: 'no_direct_link' }

// src/booking.ts
export const CARRIER_HOMEPAGES: Record<string, { label: string; url: string }>
  // AY → Finnair https://www.finnair.com · KL → KLM https://www.klm.nl · SK → SAS https://www.flysas.com
export function bookingPageUrl(option: Option): string | null
  // null if bookingRef missing; otherwise '/book.html?ref=…&carrier=…&flight=AY1302&date=2026-10-29'
  // date = departure date in the departure airport's timezone (timezoneFor)

// src/api.ts
export async function fetchBookingLink(ref: string): Promise<BookingLinkResult> // throws Error if !ok

// src/bookingPage.ts
export interface BookingPageDeps {
  search: string                                   // location.search
  render: (html: string) => void                   // set content of #app
  fetchLink: typeof fetchBookingLink
  navigate: (url: string) => void                  // location.replace
}
export async function runBookingPage(deps: BookingPageDeps): Promise<void>
```

## Call stack

**Search (changed, same number of calls):**
`SearchController` → `SearchFlightsHandler` → `IgnavFlightSource::search` →
`toItinerary` (reads `ignav_id`) → `new Itinerary(..., bookingRef)` →
`SearchResultPresenter::option` → `bookingRef` in JSON → `Option.bookingRef`.

**Book:**
1. `OptionCard` button `@click.stop` → `bookingPageUrl(option)` →
   `window.open(url, '_blank', 'noopener')`.
2. New tab: `book.html` → `bookingPage.ts` → `runBookingPage(deps)`:
   `render('Fetching booking link at KLM…')` → `fetchBookingLink(ref)`.
3. `GET /api/booking-link` → `BookingLinkController` → validate → 
   `BookingLinkSource::linkFor` → `IgnavBookingLinkSource`:
   cache hit → return; else `POST ENDPOINT {ignav_id}` → `pickDirect`
   → `BookingLink` → cache → return. `BookingLinkUnavailable` → 502.
4. `runBookingPage`: `found` → `navigate(url)`; `no_direct_link` →
   `render(text + link CARRIER_HOMEPAGES[carrier])`; Error → `render(error message)`.
   Missing `ref`/`carrier` in query string → error message, no fetch.

## Test plan

### Backend
`IgnavFlightSourceTest`
- `test_it_carries_the_ignav_id_as_booking_ref` — real fixture → Finnair option has the `ignav_id` from the fixture.
- `test_a_missing_ignav_id_gives_a_null_booking_ref` — option stays, ref null.

`SearchControllerTest`
- `test_each_option_exposes_its_booking_ref` — through container: `options[0].bookingRef` = ref from the double.

`IgnavBookingLinkSourceTest` (MockHttpClient + `SpyCacheItemPool`/ArrayAdapter, **real fixture**)
- `test_it_posts_only_the_ignav_id_with_the_api_key` — no `market` (measured: else 400)
- `test_it_returns_the_airlines_own_link_from_the_measured_response`
- `test_ota_links_alone_give_no_direct_link`
- `test_the_cheapest_of_several_direct_links_wins`
- `test_a_non_https_url_is_not_used` — `javascript:`/`http:` → no direct link
- `test_a_second_request_is_served_from_cache` — 2× `linkFor`, 1 HTTP call
- `test_no_direct_link_is_cached_too` — 2×, 1 HTTP call
- `test_a_failure_is_not_cached` — 500 then 200 → 2 HTTP calls, second gives link
- `test_http_error_throws_unavailable` / `test_unreadable_json_throws_unavailable` / `test_missing_booking_options_throws_unavailable`

`BookingLinkSourceWiringTest` (KernelTestCase)
- `test_the_container_wires_ignav_as_booking_link_source`
- `test_it_uses_the_app_cache_pool` — real wiring caches: two requests via container service with a MockHttpClient → 1 call (catches a forgotten `$cache` argument).

`BookingLinkControllerTest` (WebTestCase, `FixedBookingLinkSource` in container)
- `test_a_direct_link_is_returned_as_found`
- `test_no_direct_link_is_returned_as_such`
- `test_an_invalid_ref_is_400_and_never_reaches_the_source` — `$calls === 0`
- `test_an_unavailable_source_is_502`

### Frontend
`booking.spec.ts`
- `bookingPageUrl` contains ref, carrier, flight numbers and date (URL-encoded)
- date follows the departure airport: 23:30 UTC from HEL → next day
- `null` without `bookingRef`

`OptionCard.spec.ts`
- shows "Book" if `bookingRef` is there; no button without
- click → `window.open` with `bookingPageUrl(option)`, `'_blank'`, `'noopener'`
- click emits **no** `select`
- Enter on button emits **no** `select`

`bookingPage.spec.ts` (`runBookingPage` with mock deps)
- shows first "Fetching booking link at KLM…"
- `found` → `navigate(url)`
- `no_direct_link` → text with airline + link to `CARRIER_HOMEPAGES`
- fetch throws → error message, no navigate
- missing ref → error message, fetch never called

Every test must visibly fail without its corresponding code (mutation per slice).

## Least confident decisions
1. **Only `provider_type === "airline"`** (revised 2026-10-03 at owner's proposal, after measurement). No name matching, no `carrier` to backend.
   Residual risk: on a mixed flight (e.g. KL + SK) the airline link could be the other airline's — still an official link, so acceptable.
   KLM/SAS we first see on proof of slice 2.
2. *(removed — backend no longer gets `carrier`)*
3. **Cache also "no direct link" for 30 min.** Saves credits, but if ignav briefly had no airline link, you see that for half an hour.
4. **Plain TS for `book.html`** instead of Vue. Smaller and faster, but a
   second style in the codebase.
5. **"Cheapest direct link"** on multiple: could be a no-baggage fare when you want a pricier one. We don't show the price, so you pick on the airline's own site.
6. **Expired `ignav_id`**: unknown how ignav signals it (404? empty list?). Now:
   anything that isn't a valid response → 502 "couldn't be fetched".
