# ADR-0003: ignav as flight source, instead of Bright Data

**Status:** Accepted
**Date:** 2026-09-17 (retroactively documented on 2026-10-02)
**Deciders:** owner

## Context
The first flight source was `BrightDataFlightSource`. It fetched Google Flights HTML via Web Unlocker
but parsed `best_flights` and `other_flights`. That is the JSON schema of **SerpApi**,
not what Bright Data returns. Bright Data has no Google Flights product; the adapter thus
never worked against the real API. Its 33 tests fed the invented schema and missed the gap
as a result. It was the seventh Critical, and again it was in a seam: between the adapter
and the API it was supposed to talk to.

## Decision
The flight source becomes **`IgnavFlightSource`**: `POST https://ignav.com/api/fares/one-way`
with the `X-Api-Key` header, from env `IGNAV_API_KEY`. Bright Data and its tests were
removed (`b5c74f5`). The `FlightPriceSource` port stays unchanged; the domain mapping
moved over almost unchanged.

Owner decisions within the adapter:
- `requires_self_transfer: true` → reject option (no through ticket).
- `price.status` → ignore; accept every price.
- `price.amount` is in **whole euros** and is converted to cents
  (`round($euros * 100)`).
- Airlines and airports outside the allowed set (such as BT, TK, RIX, IST,
  which appeared in the first real response) → skip.

## Options Considered

### Option A: ignav (chosen)
| Dimension | Assessment |
|-----------|------------|
| Complexity | Low: structured JSON and times in UTC |
| Cost | 1000 free requests/month, only successful calls count, no rate limit |
| Reliability | Tested against **a real measured** response (`fixtures/ignav-ams-hel.json`) |

**Pros:** a real API with a real schema; the adapter became smaller on balance.
**Cons:** the credit limit is limited; an external party without SLA.

### Option B: Fix Bright Data (scrape Google Flights HTML)
| Dimension | Assessment |
|-----------|------------|
| Complexity | High: HTML parsing of a page that changes without notice |
| Cost | 5,000 credits/month |

**Pros:** the account already existed.
**Cons:** fragile, and the existing adapter had to be completely rewritten anyway.

## Trade-off Analysis
The real lesson wasn't in the choice of vendor: **an adapter must
be tested against a measured response, not an assumed schema.** ignav
won because it's a real API, and because there's a real response as a fixture to go with it.

## Consequences
- Each search costs 2 ignav calls (HEL and TKU), and a round trip 4 (see ADR-0001).
- `IGNAV_API_KEY` belongs in `api/.env.local`, never in `api/.env` (which is in
  git). In `APP_ENV=test`, `.env.local` is not loaded; no test touches the network.
- Revisit if the free credit runs out structurally.
- For a new source: first capture a real response as a fixture, only then write the
  adapter.

## Action Items
1. [x] Built according to `docs/history/ignav-flightsource/plan.md`.
