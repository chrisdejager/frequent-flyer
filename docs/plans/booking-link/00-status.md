# Status: Book button per flight

- Gate 1 — Product: APPROVED 2026-10-03
- Gate 2 — Architecture: APPROVED 2026-10-03
- Gate 3 — Program Design: APPROVED 2026-10-03
- Gate 4 — Slice plan: APPROVED 2026-10-03

## Slices
- [x] Slice 1 — tracer bullet: button → book.html → stub endpoint → finnair.com
- [x] Slice 2 — happy path: ignav_id through the chain, real adapter picks airline link
- [x] Slice 3 — no direct link: only third_party/not-https → homepage; 400 validation
- [x] Slice 4 — errors: 502 → error message in the tab
- [x] Slice 5 — cache: second click costs no ignav call
- [x] Slice 6 — wrap-up: ADR-0006, handoff, full suites

## Notes for a fresh session
- Owner request (2026-10-02): "I'd like direct booking links in the list. But I saw
  that with ignav each link fetch is a request. So don't do it upfront for every link,
  only when you click 'Book'. So a new window opens with the booking screen for that
  airline and flight."
- ignav: `POST https://ignav.com/api/fares/booking-links` with `ignav_id` from search
  response (already in fixture `ignav-ams-hel.json`). Response:
  `booking_options[].links[]` with `provider_name`, `provider_type`, `price`, `url`
  — both airline-direct and OTAs. Default `market` is "US".
  Docs: https://ignav.com/docs/booking-links — quota counting not documented.
  **No real response measured yet** (ADR-0003: first real response as fixture).
- Gate 1 approved 2026-10-03, **without** the price-difference point ("KLM now €162"):
  the price from the booking link is not shown.
- Gate 3 approved 2026-10-03. Owner confirmed homepages: finnair.com, klm.nl, flysas.com.
- **Measurement 2026-10-03** (`measurements/`): booking-links with `market` → 400
  (`conflicting_booking_lookup_mode`), so request = only `{ignav_id}`. Gave for AY1302
  2 links: Finnair `airline` €143 (deep link with flight number and date) and
  lastminute.com `third_party` €199. Docs 02/03 corrected accordingly. Cost:
  1 search call + 1 link call.
- **Reconsidered 2026-10-03 (owner, after measurement):** "direct" = `provider_type ===
  "airline"`, period. No name matching, no `carrier` to `/api/booking-link`;
  `carrier` stays only in `book.html` query string for text and homepage. Gates 2 and 3
  updated (02/03), incorporated in Gate 4.
- **Slice 1 done (2026-10-03).** Frontend and backend stub built in parallel by two
  haiku agents (owner request), tests upfront by the main session. Review found one bug:
  unknown airline was double-escaped → test added that failed, then fixed. Mutations
  (`.stop` removed on click, on keydown, `noopener` removed) each make exactly their test
  fail. Proof in real app (`/api/search` with fetch stub to save credit): click "Book ↗"
  on AY1302 → new tab `/book.html?carrier=AY&flight=AY1302&date=2026-10-29` → within 3 s
  to `https://www.finnair.com/`. Frontend 62/62, PHP 134/134, build with `dist/book.html`.
  New files: `web/src/bookingPage.ts`, `web/src/book.ts`, `web/src/book-main.ts` (entry;
  `book.ts` stays without side effects), `web/book.html`, `api/src/Infrastructure/Http/BookingLinkController.php`
  (stub). Committed to master (feat(booking): tracer bullet …).
- **Plan for slice 2 (agreed 2026-10-03):** ignav adapter `IgnavBookingLinkSource`
  + its tests → one haiku agent (signatures + tests, no literal code). `ignav_id`
  through the chain (adapter → `Itinerary` → presenter → `Option.bookingRef` → query
  string) do yourself: that's the seam. Proof in app: 1 real search (2 calls) + click on
  AY, KL, SK (3 link calls). Don't ask owner again; this is agreed once he says "on to
  slice 2".
- **Slice 2 done (2026-10-03).** `ignav_id` → `Itinerary::$bookingRef` → presenter →
  `Option.bookingRef` → `?ref=` in `book.html`; button only with ref.
  `IgnavBookingLinkSource` (haiku agent against pre-written tests, then
  trimmed) picks the cheapest `airline`-link with https; controller real through the
  port. No cache, error handling or ref validation yet (slices 3–5): an
  ignav error now still gives 500. PHP 144/144 (also `--network none`),
  frontend 65/65, build green. Mutations: every new test goes red without its
  code; only removing the port alias stays green — Symfony
  auto-aliases an interface with one implementation, the alias is only explicit.
  **Proof (curl through the real app; Chrome extension was not connected):** 1 search
  (2 calls), 6 options all with ref; 3 link-calls:
  AY1302 → finnair.com-deeplink with `departureFlightNumbers=AY1302`;
  KL1251 → klm.nl-deeplink with `KL1251`;
  **SK556+SK2728 → klm.nl-deeplink with KL2858+KL8528** — same flights
  (codeshare, 11:15 AMS via ARN, €484), but ignav gave no SAS-native link.
  That is the accepted risk from "least confident decisions" #1.
- **SAS → KLM link explained (measurement 2026-10-03, flight 26-11-2026).** Both
  SAS options (SK556+SK2728, SK1556+SK2720; according to `operating_carrier_name`
  entirely flown by SAS) gave two `airline`-links: KLM-deeplink with price
  and flights (KL-codeshare numbers), and SAS as `http://www.flysas.com/nl/nl/?gclid=…`
  — only the homepage, **http** and **without price**. The adapter drops that
  (no https; no price counts as most expensive), so KLM wins. That is desired: the
  KLM link lands on the right flights. Not an exception but structural for SAS.
  Cost of this measurement: 2 search-calls + 2 link-calls (plus 1 raw search-call AMS→TKU 29-10).
- **Slice 3 done (2026-10-03).** `no_direct_link` → tab with "No direct booking
  link from X found" + link to homepage (only for AY/KL/SK;
  `Object.hasOwn`, otherwise `carrier=constructor` became a "known" airline
  with `href="undefined"`). Controller: 400 on every ref that is not `^[0-9a-f]{32}\z`
  is, even `?ref[]=` and a trailing newline (`$` let that through — test first
  red, then `\z`); source never called. Second measured fixture
  `ignav-booking-links-sas.json` (SAS http-homepage without price). PHP 161/161
  (also `--network none`), frontend 69/69, build green. Mutations: all red, except one
  that left a gap — `return` after `navigate` removed stayed green; test
  tightened, now red. Proof: curl on 5 invalid refs → 400 in the real app.
  Tab itself not shown in the browser (Chrome extension not connected); a
  real flight without airline-link we don't know.
- **Browser proof slice 2/3 (2026-10-03, Chrome).** Click Book at AY1302 → within
  5 s finnair.com with AMS→HEL Thu 29 Oct, AY1302; Finnair selected the flight and
  went to `passenger-details`, gave there "unexpected error in the booking system"
  (their side; possibly automated tab — owner tests themselves). Click on
  SK556 → klm.nl metasearch landing with KL2858/KL8528; after the cookie banner the
  tab fell back to `/search/advanced` (not investigated). Slice 3 tab
  shown with fetch stub: "No direct booking link from SAS found" + flysas.com.
- **‼ SAS price problem (owner discovered, 2026-10-03, flight 26-11).** ignav gives
  for SAS flights the price of the KLM codeshare: SK556+SK2728 €374 in the app,
  €193 on flysas.com; SK1556+SK2720 €515 vs €259. Google Flights shows for SAS
  also "price unavailable" and OTAs ~€186. Result: the app places SAS incorrectly
  last — hits the core (cheapest journey), not just the booking link.
  Owner's workaround, tested: `https://www.flysas.com/nl-en/book-new/revenue/travelers?origin=AMS&destination=TKU&outboundDate=2026-11-26&adults=1&youths=0&children=0&infants=0&outFlightNumbers=556%2C2728`
  — without `outFareBasis` (we don't know it) SAS drops the flight numbers and
  shows the flight list for that date and route. No decision yet.
- **Owner decision 2026-10-03: Build SAS link ourselves.** At `carrier === 'SK'` the
  button opens directly `https://www.flysas.com/nl-en/book-new/revenue/travelers?origin…&
  outboundDate…&adults=1…&outFlightNumbers=556,2728` — no ignav-call, no
  `bookingRef` needed (`web/src/booking.ts`, `sasBookingUrl`). Deviates from "direct =
  provider_type airline" (Gate 2/3); document in ADR-0006 (slice 6).
  `carrier` SK means always: all legs SK (priority KL > AY > SK in the adapter).
  Frontend 72/72, 4 mutations red. Browser: button opens exactly that URL; same
  URL by hand → SAS flight list 26 Nov, SK556 €193.
  **SAS price problem** (too high ignav price in the ranking): handle separately after
  slices 4–6 (owner).
- **Owner decision 2026-10-03: SAS price = unknown (build after slice 6).** "Never the
  codeshare price, always book at the airline itself." The ignav search response
  makes no distinction (same `status: verified`, no field that reveals it);
  only booking links show it and that costs a call per option. Therefore: all
  SK options show with "price unknown, see flysas.com" in the unranked
  bucket, without extra calls. Revert once SAS provides their own prices again
  (re-measure occasionally). Affects the domain: a flight without known price does not
  exist yet. Idea "lowest OTA + €10" falls away: ignav gave neither SAS option
  any third_party-links.
- **Slice 4 done (2026-10-03).** Every non-2xx, transport error, unreadable JSON,
  `null` or missing/non-list `booking_options` → `BookingLinkUnavailable`
  (+ warning in the log) → 502 `{"error":"The booking link could not be fetched."}`.
  An empty `booking_options` stays "no direct link". Tab: error message
  (escaped) + homepage link; without `ref` or `carrier` → "incomplete", no fetch.
  PHP 170/170 (also `--network none`), frontend 77/77, build green; 10 mutations
  red. **Measured:** unknown ignav_id (`000…0`) → ignav **HTTP 400** (answers
  least-confident #6 for a made-up id; a real expired id not measured).
  Proof: curl → 502 in the real app, warning in the php log. Tab not shown in the
  browser (extension disconnected again).
- **Slice 5 done (2026-10-03).** `IgnavBookingLinkSource` caches in `cache.app`
  (`ignav.booking.<ref>`, 1800 s): direct link as `[url, provider]`, "no link"
  as sentinel; failures throw before storing and thus never cached.
  Wiring test through the real container (`http_client` replaced by a
  MockHttpClient, random ref because `cache.app` in test on disk):
  2 requests → 1 call. PHP 176/176 (also `--network none`), frontend 77/77.
  Mutations red, except `@cache.system` instead of `@cache.app` (caches just as well;
  no behavior difference). **Proof real app:** same ref 3× → 4.9 s, 0.012 s,
  0.006 s, identical responses; browser: cached AY1302 ref jumps straight
  to finnair.com. Slice 4 tab now also seen in the browser
  ("The booking link could not be fetched." + Finnair link).
  Ignav_id from a search ~2 hours earlier still worked.
- **Slice 6 done (2026-10-03).** ADR-0006 (on request, only the airline itself,
  SAS built ourselves, cache 30 min, failure ≠ no link) + index. `OVERDRACHT.md` and
  `progress.md` updated (outside git). Final state: PHP 176/176, also
  `--network none`; frontend 77/77; build with `dist/book.html`. **Feature complete.**
  Next: SAS price = unknown (own feature, gates again).
