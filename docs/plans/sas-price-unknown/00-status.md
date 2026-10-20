# Status: SAS price unknown

**DEFERRED (owner, 2026-10-03).** Not built. The ignav search response doesn't say
where the price comes from; generically testing "price from airline only" requires
a booking-links call per option (6–8 extra per search). Owner: "sounds like we need
a different price API" — and assumes it's a temporary SAS issue. Until then: the
custom-built flysas.com link (ADR-0006, `8290829`) to manually check the SAS price
with one click. Also considered but not chosen: a generic list `CARRIERS_WITHOUT_OWN_PRICE=SK`.
Restart this plan if the problem persists (check periodically: SAS search price vs
flysas.com).

**Checked 20 Oct 2026:** unchanged at ignav. SK556+SK2728 AMS→TKU 26 Nov:
search price €371; booking-links gives KLM (codeshare KL2858+KL8528, €371)
and SAS only as an `http:` homepage without price. Removing the flysas.com
link would send the SAS button to KLM. The owner saw correct SAS prices in
the app; whether flysas.com itself is now ~€371 was not checked.

## Where this stands — 20 Oct 2026 (pick up here)

**The problem is structural, not temporary.** The owner checked Google Flights
for SK556 11:15 + SK2728 14:30 AMS→TKU on 26 Nov: €371 appears nowhere; Google
says it has no price for that flight. ignav gives every SAS-only ticket the KLM
codeshare price. Measured 20 Oct 2026 (search + booking-links per date):

| Date   | Price in app | booking-links                         |
|--------|--------------|---------------------------------------|
| 5 Nov  | €502         | KLM €502, SAS without price           |
| 12 Nov | €502         | KLM €502, SAS without price           |
| 26 Nov | €371         | KLM €371 (KL2858+KL8528), SAS http-homepage without price |
| 3 Dec  | €502         | only Kiwi €220                        |

Not stale data: ignav does cache searches (a repeat call 18 min later came back
in 0.5 s with the same `observed_at`), but the price was minutes old, not days.
Mixed KLM+SAS tickets (e.g. KL1215+SK2728 €301) are KLM's own price and fine.

**Rejected by the owner (20 Oct 2026):**
- Taking a booking agency's price (e.g. Kiwi) as the SAS estimate, or a
  "check other sellers" button calling booking-links on click: "too complex a
  workaround". Also: agencies were found on only 1 of 4 dates, booking-links
  took up to 20 s, and it would conflict with "no estimates in sorting".
- Duffel (only source found with a direct SAS NDC connection; ~$0.005 per
  search without bookings): not interested.

**Researched, ruled out:** Amadeus Self-Service (shut down 17 Jul 2026), Kiwi
Tequila (invite only), Skyscanner (commercial only), FlightAPI.io (20 free
calls, then from $49/month). SerpApi, Apify Google Flights actors and
fast-flights all read Google Flights, so their *list* price will likely miss
SAS just like ignav.

**Next step: a SerpApi experiment (open; owner can't create an account yet).**
The owner sees agency prices on Google Flights that ignav's booking-links
don't return; SerpApi's per-flight booking options (`booking_token` request)
might have them. Plan, ~3 calls of the free tier (≈250/month), nothing built:
1. Owner creates a free SerpApi account and puts `SERPAPI_KEY=...` in
   `api/.env.local` (never in `api/.env`).
2. Call the Google Flights engine for AMS→TKU 2026-11-26 one-way (`type=2`,
   `gl=nl`, `currency=EUR`) and look at the SK556+SK2728 entry: price missing,
   €371, or something else?
3. Call booking options with that flight's `booking_token`; compare the sellers
   and prices with ignav's booking-links (above) and with what the owner sees
   in Google Flights.

If SerpApi isn't better: the fallback is this plan's slices A+B below
(SAS-only = "price unknown", unranked, flysas.com button stays).

---

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
