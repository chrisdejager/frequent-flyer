# ADR-0006: Booking link on demand, only from the airline itself

**Status:** Accepted
**Date:** 2026-10-03
**Deciders:** owner

## Context
The owner wanted a button per flight that goes directly to the airline's booking screen.
ignav provides these links via `POST /api/fares/booking-links` with the `ignav_id` from
the search response, but each request is a call against the limited credit (1000 per
month, see ADR-0003). A search shows six to eight options; fetching all links upfront
would multiply usage four times.

Measured on 2026-10-03:
- With a `market` included, booking-links returns a 400 (`conflicting_booking_lookup_mode`).
  So the request is just `{ignav_id}`.
- The response contains per option links with a `provider_type`: `airline` (the airline
  itself) or `third_party` (a travel agency).
- Finnair and KLM give a real deep link with date and flight number.
- **SAS** gives as `airline` only `http://www.flysas.com/nl/nl/` without price.
  Alongside that is the **KLM codeshare**, with the same aircraft and flight,
  but at approximately double the rate: €374 vs. €193 at SAS itself.
- An unknown `ignav_id` returns HTTP 400. An ignav_id from a search a few hours
  earlier still worked.

## Decision
1. **Fetch only on click.** The "Book ↗" button opens `book.html` in a new tab (`noopener`).
   That page calls `GET /api/booking-link?ref=…`, and the server fetches it from ignav.
2. **Airline only.** Direct means `provider_type === "airline"` and https; with multiple links
   the cheapest wins. We never use travel agencies. If there's no direct link, the tab shows
   that, with the airline's homepage.
3. **We build SAS ourselves.** When `carrier === 'SK'` the button opens directly
   `https://www.flysas.com/nl-en/book-new/revenue/travelers?…` with route, local departure
   date, and flight numbers. That costs no ignav call. Without `outFareBasis`, which we
   don't know, SAS shows the day's flight list.
4. **Server-side cache, 30 minutes.** Key `ignav.booking.<ref>` in `cache.app`. Even
   "no direct link" is cached, but errors never are.
5. **Error ≠ no link.** Every unusable response becomes `BookingLinkUnavailable` and thus
   a 502. This covers non-2xx, transport errors, broken JSON, and missing `booking_options`.
   A ref that doesn't match `^[0-9a-f]{32}\z` gets a 400 and never reaches ignav.

## Options Considered

### Option A: on demand, server-side cache (chosen)
| Dimension | Assessment |
|-----------|------------|
| Cost | 1 call per first click on a flight; repeated clicks free |
| Speed | ~5 s on first click, then instant |
| Complexity | A second Vite entry and one endpoint |

### Option B: fetch all links immediately when searching
**Pros:** the button works instantly, and per-provider price is known upfront.
**Cons:** 6–8 extra calls per search while you usually use at most one. Rejected by owner.

### Option C: also allow travel agencies
**Pros:** more often a link, sometimes cheaper.
**Cons:** the owner wants to "always book with the official airline". Rejected.

## Trade-off Analysis
The tab page is plain TypeScript rather than Vue: small and fast, but adds a second
style to the codebase. The logic lives in `runBookingPage(deps)` with injected
dependencies, so it can be tested without DOM.

The SAS decision deviates from rule 2. By that rule SAS would always get the KLM
codeshare: an official link, but at KLM's price. Because the owner never wants
the codeshare price, SAS goes outside ignav.

## Consequences
- One ignav call per first click per flight per half-hour.
- `bookingRef` (the `ignav_id`) travels with every option from `/api/search`. An
  option without a ref gets no button, except for SAS.
- With a mixed flight the airline link could be from the other carrier. That's
  still official, and thus acceptable.
- **Open:** ignav gives SAS flights the KLM codeshare price as the search price.
  Owner decision: all SK options get "price unknown". That's separate from this ADR
  and follows as its own feature.
- Revisit if SAS sends its own prices and deep links again (check periodically),
  or if ignav changes the quota counting for booking links.

## Action Items
1. [x] Built in six slices according to `docs/plans/booking-link/`.
2. [ ] SAS price = unknown (separate feature).
