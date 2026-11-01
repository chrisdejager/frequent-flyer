# Leiden ↔ Turku flight finder — design

**Date:** 2026-09-16
**Status:** approved, ready for implementation plan
**Source of requirements:** [`intake.md`](intake.md)

---

## 1. Goal

Show the cheapest travel options between Leiden and Turku for a given
date, with a **fair total price** that includes the Helsinki–Turku train and
applies baggage costs per traveler.

The system is primarily a search engine, not a monitor. Price monitoring and
alerts will come on top later and reuse the same search logic.

### What it's not

- Not a booking system. It shows options; you book with the airline yourself.
- Not a general flight finder. Two routes, three airlines, two travelers.

---

## 2. Travelers

Baggage costs differ per person, so traveler is a first-class concept.

| Traveler | SAS EuroBonus | Finnair Plus |
|---|---|---|
| Chris | Silver | Silver |
| Partner | Silver | *no status* |

Result: at Finnair the same flight is more expensive for the partner than Chris,
because she has to buy checked baggage. The system must never show one price
as if it applies to both.

---

## 3. Travel patterns

There are exactly two ways to make the trip:

**A — Via Helsinki (preference: quieter)**
Direct flight AMS ⇄ HEL, then VR train HEL ⇄ TKU.
Total price = flight price + train price.

**B — Through ticket to Turku**
AMS ⇄ TKU with **exactly one connection**, connection time ≤ 2 hrs, on **one ticket**
(so you're protected if you miss the connection).
Connection points in order of preference: **ARN (Stockholm) > CPH (Copenhagen)**.
A through ticket via **HEL** (Finnair) is technically also pattern B, but in practice
coincides with pattern A and is counted there. Other connection points are not considered.

### Time windows

| Direction | Rule | Reason |
|---|---|---|
| NL → FI | Arrive HEL by **21:00** | Must catch the train to Turku |
| FI → NL | Depart HEL not before **12:00** | No late-night departure from Turku |

Preference: afternoon Finnair flight.

### Airlines

Only **Finnair (AY)**, **SAS (SK)** and **KLM (KL)**.
AY and SK are prioritized because of the loyalty status; KL is only shown if it's
actually cheaper.

> **Not built in phase 1 — deliberately deferred to phase 2** (owner's decision,
> 17 Sep 2026). All three airlines are shown, purely sorted by
> price; there is no KL-specific filter and no priority for
> AY/SK. Note the consequence for §5: it defends the brief
> `indicative` marking with the argument that KLM would be filtered out
> anyway at a marginal difference. That reasoning does not apply in phase 1 —
> the marking is the only safety net for the estimated KLM baggage surcharge.

### Date flexibility

Standard **fixed day**. Chris is tied to care appointments with the co-parent.
The partner is flexible. If ±1 day makes a substantial difference, that is shown
as a **separately marked suggestion** — never mixed with the results for the
requested date.

> **Not built in phase 1 — deliberately deferred to phase 2** (owner's decision,
> 17 Sep 2026). The ±1-day suggestion for the partner is not implemented;
> the search always shows only the requested date.

---

## 4. Baggage and total price

Base is always **Light Economy**. On top of that:

| Airline | Chris | Partner |
|---|---|---|
| **Finnair** | Silver → hand baggage free on Light | No status → buy hand baggage, or Plus fare |
| **SAS** | Silver → checked baggage free, hand baggage not | Same |
| **KLM** | No status → buy or Plus | Same |

**SAS peculiarity:** at SAS, checked baggage is free (Silver) but hand baggage
is not. The practical solution the system must follow: the hand baggage goes
as checked baggage. So no baggage charges are calculated for SAS.

For Finnair and KLM, the system also shows the **Plus price**
alongside the Light price, so you can see whether buying hand baggage separately or
taking Plus right away is cheaper.

> **Not built in phase 1 — deliberately deferred to phase 2** (owner's decision,
> 17 Sep 2026). Phase 1 calculates one amount as baggage costs, namely
> the Light→Plus upgrade amount from `FARE_UPGRADE_AY` / `FARE_UPGRADE_KL`. The
> comparison "buy separately vs. take Plus right away" does not exist; for that
> two upgrade rates per airline are needed instead of one.

### Train costs

Routes via Helsinki get the VR price HEL ⇄ TKU added; through tickets
to TKU do not. Without this correction, the two travel patterns are not
comparable and "via Helsinki" looks structurally too cheap.

Clear preference for **train (VR)** over bus.

---

## 5. Price sources

Researched on 2026-09-16. Starting point: stay within free thresholds.

| Source | Free threshold | Role |
|---|---|---|
| **Bright Data** (Google Flights) | 5,000 credits/month, permanent | Live flight prices |
| **Travelpayouts** (Aviasales Data) | Free with affiliate account | Cheapest-per-day from cache, for baseline building |
| **Apify** (VR scraper) | $5/month credits | Train prices HEL ⇄ TKU |

Expected consumption: ~120–250 Bright Data calls per month. Well within the threshold.

Apify's $5 is tighter (~20–50 runs). Train prices are therefore **aggressively
cached** and only fetched for actual travel dates.

### Dropped

- **Amadeus Self-Service** — discontinued on 17 July 2026, keys no longer work.
- **Kiwi Tequila** — open registration closed.
- **Duffel** — test mode delivers fake data, live is pay-per-booking.
- **SerpApi** — 250 calls/month free is too tight.
- **Digitraffic** — free and official, but contains only schedules, no prices.

### Known limitation: Plus fares

Google Flights delivers fare information as loose text (`"Checked baggage for a
fee"`), not as structured Light/Plus comparison. The upgrade from Light
to Plus therefore does not come from a source, but from **configuration**:

```yaml
fare_upgrade:
  AY: 16.00    # Finnair — fixed rate, reliable
  KL: 25.00    # KLM — dynamically priced, this is an estimate
```

The system must refuse to start with a missing value instead of
silently calculating €0 — an invisible zero would make Light fares structurally
look too favorable.

The difference in certainty is essential and must remain visible:

- **Finnair** applies a fixed rate for the step to Plus. The value is
  statically known and the comparison therefore solid.
- **KLM** prices dynamically. The configured value is an estimate. Every
  KLM option where the outcome depends on this upgrade is marked as **indicative** in the
  interface, so you don't read it as final.

That KLM appears only if it is clearly cheaper anyway dampens the
risk: at a marginal difference, KLM is already filtered out before the uncertain
upgrade matters.

---

## 6. Architecture

```
flights/
├─ compose.yaml           # php-fpm · nginx · node(dev) · scheduler
├─ compose.prod.yaml      # override for the Ubuntu VPS
├─ api/                   # Symfony 7
│  └─ src/
│     ├─ Domain/          ← no framework imports, pure PHP
│     │  ├─ Flight/       Itinerary · Segment · Carrier · Airport · Money
│     │  ├─ Traveller/    Traveller · LoyaltyStatus · BaggageEntitlement
│     │  ├─ Pricing/      TotalCost · BaggagePolicy · TrainSurcharge
│     │  ├─ Rules/        TimeWindow · ConnectionRule · CarrierFilter · RuleSet
│     │  ├─ Alerting/     Baseline · PriceObservation · Trigger
│     │  └─ Port/         FlightPriceSource · TrainPriceSource · PriceHistory
│     ├─ Application/     SearchFlights · EvaluateAlerts
│     ├─ Infrastructure/  Source/ · Persistence/ · Http/
│     └─ Ui/Cli/          console commands for the scheduler
└─ web/                   # Vue 3 + Vite + TypeScript
```

### Why this structure

The complexity lies in **rules, baggage and total price** — exactly the layer without
I/O. That stays pure: value objects, no database, no HTTP. That's where the
unit tests are, and they run in milliseconds.

Persistence is small: only `PriceObservation` and `Baseline` need to be saved.
Storage is **SQLite** — one file on a volume, no database server in the
compose stack. For one user and a few thousand price observations per year
that is more than enough. Doctrine mapping goes via XML in `Infrastructure/`, so
domain classes stay clean without a second model layer emerging.

The `Port/` interfaces are not ceremony: there are three replaceable external
sources, and the chance one fails is real. That is precisely the case for
dependency inversion.

### Data model

```
Itinerary      pattern (VIA_HEL | THROUGH) · segments[] · carrier
               departAt · arriveAt · connectionMinutes · fareFamily
Segment        from · to · departAt · arriveAt · flightNumber
TotalCost      basePrice · baggageCost · trainCost · total  (per traveler)
Traveller      name · loyalty: map<Carrier, LoyaltyStatus>
PriceObservation  route · travelDate · carrier · cheapestPrice · observedAt
Baseline       route · carrier · normalCheapest · source (seeded | learned)
```

### Application flow

```
HTTP GET /api/search
  → SearchFlightsQuery(date, direction, traveller)
    → FlightPriceSource.search()      ┐
    → TrainPriceSource.priceFor()     ├ Infrastructure
                                      ┘
    → RuleSet.filter(itineraries)     ┐
    → BaggagePolicy.costFor(t, i)     ├ Domain, pure
    → TotalCost.compute()             ┘
    → PriceHistory.record()
  → JSON
```

---

## 7. API

| Endpoint | Purpose |
|---|---|
| `GET /api/search?date=&direction=&traveller=` | Search options for one date |
| `GET /api/trips` | Planned trips (phase 1: hardcoded) |
| `GET /api/observations?route=&from=&to=` | Price history (phase 2) |

`direction` is `nl-fi` or `fi-nl`. `traveller` is `chris` or `partner`.

---

## 8. Frontend

Vue 3 + Vite + TypeScript, standalone SPA against the JSON API.

Phase 1 is one screen: date picker, traveler selection, results list. Each result
shows the total price with the breakdown below it expanded — flight price, baggage,
train — so it's visible why an option wins.

---

## 9. Alerts (phase 3)

Result is shown on the **dashboard**; no email, Telegram or push.

**Baselines**, seeded from experience and then self-learning from observations:

| Airline | Route | Normal cheapest | Certainty |
|---|---|---|---|
| Finnair | AMS → HEL | €134 | certain |
| Finnair | HEL → AMS | €80 | certain |
| SAS | AMS → TKU | ~€150 | uncertain |
| SAS | TKU → AMS | ~€90 | uncertain |

**Trigger A — discount period.** Price drops below the baseline. This is the
most important one: discount actions happen unpredictably.

**Trigger B — high alert.** Price rises on an already planned travel date. This
means waiting too long.

---

## 10. Docker and deploy

Locally: `docker compose up` starts everything, including Vite with hot reload.

On the Ubuntu VPS: same compose with production override. Vue is built to
static files, nginx serves them, a scheduler container runs the
recurring tasks.

Check frequency: **daily** (not explicitly chosen in the intake; daily
fits within all quotas and is fine-grained enough to catch discount actions).

---

## 11. Testing

| Layer | Tool | Phase |
|---|---|---|
| Domain: rules, baggage, total price | PHPUnit, in-memory | 1, test-driven |
| Source adapters | PHPUnit against recorded HTTP fixtures | 1 |
| API endpoints | Symfony `WebTestCase` | 1 |
| End-to-end | Playwright | 2 |

The recorded fixtures are essential: tests never burn Bright
Data credits, run offline and are deterministic.

---

## 12. Phases

| Phase | Content |
|---|---|
| **1** | Search + rules + baggage + total price + dashboard. Only 25 and 29 October 2026. |
| 2 | Record price history; Playwright e2e; **show Plus price alongside Light (§4)**; **show KL only if it's really cheaper (§3)**; **±1-day suggestion for the partner (§3)** |
| 3 | Discount and high-alert notifications on the dashboard |
| 4 | Read Google Calendar instead of manual trip list |

### Planned trips (phase 1, hardcoded)

```
25 Oct 2026   partner   FI → NL
29 Oct 2026   partner   NL → FI
```

Both planned trips are the partner's. Her profile (SAS Silver, Finnair
no status) is therefore immediately the relevant case.

---

## 13. Open points

1. ~~**Plus fares.**~~ *Resolved 2026-09-16:* statically configurable.
   Finnair is a fixed, known rate; KLM is dynamic and therefore an estimate
   marked as indicative. See §5.
2. **"How far in advance is buying hand baggage separately the cheapest?"**
   From the intake, but no source delivers this structured. Deferred
   to phase 3; possibly only to be included as a static rule of thumb.
3. **SAS baselines** (~€150 / ~€90) are uncertain and will be replaced by learned
   values after a few weeks of observations.
4. **Bright Data registration** requires an account; whether a payment method is
   requested in practice is only revealed at signup.
