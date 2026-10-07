# Status: SAS price unknown

**DEFERRED (owner, 2026-10-03).** Not built. The ignav search response doesn't say
where the price comes from; generically testing "price from airline only" requires
a booking-links call per option (6–8 extra per search). Owner: "sounds like we need
a different price API" — and assumes it's a temporary SAS issue. Until then: the
custom-built flysas.com link (ADR-0006, `8290829`) to manually check the SAS price
with one click. Also considered but not chosen: a generic list `CARRIERS_WITHOUT_OWN_PRICE=SK`.
Restart this plan if the problem persists (check periodically: SAS search price vs
flysas.com).

**Fast version** (owner, 2026-10-03): no four gates; product choices from
`01-product.md` + mockup apply, design below, two slices.

## Slices
- [ ] Slice A — backend: toggle + "price unknown" in `/api/search`
- [ ] Slice B — frontend: card, header "Unranked — price unknown", total bar

## Design
- **Toggle** `SAS_PRICES_TRUSTED` (env, bool, in `api/.env` at `0`), like
  `TRAIN_COST_ENABLED` (ADR-0004) in `TotalCostCalculator` — one place.
- **Domain:** `TotalCost` gets `bool $basePriceKnown` and `isRanked()` =
  `trainCostKnown && basePriceKnown`. `TotalCostCalculator::calculate` sets
  `basePriceKnown = false` if `itinerary->carrier === Carrier::Sas` and the
  toggle is off. `carrier` SK means: all legs SK (adapter priority
  KL > AY > SK); a mixed KL+SK ticket is KLM's own price and stays as is.
- **Handler** sorts on `isRanked()` (now: `trainCostKnown`).
- **Presenter:** `cost.priceKnown`; when `false` `basePrice`, `total` and
  `totalFormatted` are **`null`** — the codeshare price doesn't leave the
  server, so the UI **cannot** show it.
- **Frontend:** `Cost` fields nullable + `priceKnown`; `isRanked(option)` in
  `roundTrip.ts` replaces the lone `trainCostKnown` checks (partition,
  defaultSelection, combine). Card: "price unknown · see flysas.com", row
  "Flight: unknown". Header: "Unranked — price unknown". Total bar:
  "unknown" instead of an amount if a chosen side has no price.

## Tests (beforehand)
- `TotalCostCalculatorTest`: SK + off → `basePriceKnown false`, not ranked;
  SK + on → known; AY/KL + off → known.
- `SasPriceSwitchWiringTest` (real container, both env settings).
- `SearchControllerTest`: SAS option comes after cheaper and more expensive ranked
  options, with `priceKnown false` and `null` amounts; no 37400 in the JSON.
- Frontend: `partition`/`defaultSelection`/`combine` with a SAS option; OptionCard
  shows "price unknown" and no "€"; LegColumn header; TotalBar "unknown".

## Notes for a fresh session
- Trigger (2026-10-03, see `docs/plans/booking-link/00-status.md`): ignav gives
  the KLM codeshare price as search price for SAS flights. Measured 26-11-2026:
  SK556+SK2728 € 374 in the app, € 193 on flysas.com; SK1556+SK2720 € 515 vs € 259.
  The search response does not reveal it (same `status: verified`).
- Owner decision: "never the codeshare price, always book with the official airline"
  → all SK options "price unknown", without extra ignav calls.
- Owner thinks it is **temporary** → toggle.
- The Book button for SAS already opens a self-built flysas.com link (ADR-0006).
