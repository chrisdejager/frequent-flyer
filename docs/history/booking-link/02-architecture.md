# Architecture: Book button per flight

## Fit
- **`IgnavFlightSource`** now throws away `ignav_id`. It needs to come through:
  `Itinerary` gets an optional source reference (`?string`, framework-free),
  `SearchResultPresenter` sets it as `bookingRef` in each option. No other
  domain logic changes; searching costs exactly the same ignav calls as now.
- **New port** `BookingLinkSource` in `Domain/Port`, with an ignav adapter
  `IgnavBookingLinkSource` in `Infrastructure/Source` alongside the flight source.
  Separate from `FlightPriceSource`, so the search port doesn't change shape.
- **New controller** `BookingLinkController` in `Infrastructure/Http`, alongside
  `SearchController`.
- **Frontend:** `OptionCard` gets a button (`@click.stop`, so the row isn't
  selected). New: a second small page `book.html` (own Vite
  entry, plain TS, no Vue needed) that runs in the new tab.

### Why a separate page in the new tab
The button does `window.open('/book.html?…', '_blank', 'noopener')` **synchronously**
on click — then no browser blocks it. That page shows "Fetching booking
link…", fetches the link, and does `location.replace(url)`. Alternative
(open blank tab and fill from the list) requires `document.write` and a
`window.opener` connection that the airline's site also gets; rejected.

### "Second click costs nothing": cache on the server
Because each tab is a fresh page, the list page can't pass the link to the
new tab. Therefore the **backend** caches the response per
`ignav_id` in `cache.app` (same approach as `ApifyTrainPriceSource`). This is
stronger than Gate 1 asked ("while the page is open"): it also applies after reload.
- TTL success: 30 min. "No direct link" also 30 min. Failures **not** cached
  (an outage you should be able to retry).

## Endpoints
- `GET /api/booking-link?ref=<ignav_id>` — fetches (or reads
  from cache) the booking links and returns exactly one outcome:
  - `200 {"status":"found","url":"https://…","provider":"KLM"}`
  - `200 {"status":"no_direct_link"}` — only OTAs or nothing
  - `400` on invalid `ref` (must be 32 hex characters)
  - `502 {"error":"…"}` on outage/unreadable response from ignav
  No `carrier` parameter (revised 2026-10-03): `provider_type: "airline"` suffices.

## Data
No tables. Only cache items `ignav.booking.<ref>` in `cache.app`
(filesystem pool, already configured). Read: `getItem` → hit = no ignav call.

## Flow
1. `/api/search` → `IgnavFlightSource` now also reads `ignav_id` → `Itinerary`
   → presenter → `option.bookingRef` in the JSON.
2. Click "Book" in `OptionCard` → `window.open('/book.html?ref=…&carrier=KL&flight=KL1251&date=2026-10-29')`.
   Without `bookingRef` (shouldn't happen, but defensive): button not shown.
3. `book.html` → `fetch /api/booking-link?ref=…`
   → `BookingLinkController` → `BookingLinkSource::linkFor(ref)`
   → `IgnavBookingLinkSource`: cache hit? → else `POST https://ignav.com/api/fares/booking-links`
   `{ignav_id}` (**without** `market`: ignav returns 400 `conflicting_booking_lookup_mode`, measured 2026-10-03) → pick the airline's own link → cache → return.
4. `book.html`: `found` → `location.replace(url)`; `no_direct_link` → text +
   link to airline homepage (fixed list in frontend:
   AY finnair.com, KL klm.nl, SK flysas.com); error → error message.

### "The airline itself" recognized
**Revised after measurement 2026-10-03 (owner's proposal):** a link counts as direct if
`provider_type === "airline"`. Travel agencies are `third_party`. No name matching
on `provider_name`; the server doesn't need to know the airline. The
`carrier` in the `book.html` URL stays only for text and homepage.
Multiple direct links (e.g. different `fare_name`s)? Take the cheapest
(we use the price only to choose, we don't show it).

## External
- ignav `POST https://ignav.com/api/fares/booking-links`, header `X-Api-Key`,
  env **`IGNAV_API_KEY`** (exists already). No new env vars.
- **Measure first (ADR-0003):** before the adapter capture one real response as
  fixture `fixtures/ignav-booking-links.json`. Costs 1 search call + 1 link call
  of the credits. With it we document: the values of `provider_type`, how
  `provider_name` for AY/KL/SK is called, whether the `url` is directly usable, and whether
  `market: "NL"` works. → **Measured: it doesn't** (see below).
- Unknown: whether booking-links calls count against quota, and how long an
  `ignav_id` stays valid. On an expired id → the error message from Gate 1.
- Existing risk (I7): the API has no auth; `/api/booking-link` like
  `/api/search` is freely callable and costs credits. The `ref` validation and the
  cache limit that, don't solve it.

## Measurement 2026-10-03 (after approval, correction)
Raw response: `measurements/2026-10-03-booking-links-ay1302.json` (Finnair AY1302, AMS→HEL, 29 Oct).
- **`market` must not be** included with an `ignav_id`-lookup → 400. The request is only `{ignav_id}`.
- Response in **3.0 s**; 1 `booking_option` with 2 links:
  - `provider_type: "airline"`, `provider_name: "Finnair"`, €143 — a **deep link**
    `https://www.finnair.com/nl-en/booking/flight-selection?…&departureDate=2026-10-29&departureFlightNumbers=AY1302&…`
  - `provider_type: "third_party"`, `provider_name: "lastminute.com"`, €199.
- No `fare_name`. The response also contains the `itinerary` with `marketing_carrier_code`.
- KLM and SAS not yet measured; not needed up front, since we only match `provider_type`.
