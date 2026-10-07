# ADR-0003: ignav als vluchtbron, in plaats van Bright Data

**Status:** Accepted
**Date:** 2026-09-17 (retroactief vastgelegd op 2026-10-02)
**Deciders:** eigenaar

## Context
De eerste vluchtbron was `BrightDataFlightSource`. Die haalde via Web Unlocker de
HTML van Google Flights op, maar parste `best_flights` en `other_flights`. Dat is
het JSON-schema van **SerpApi**, niet wat Bright Data teruggeeft. Bright Data
heeft geen Google Flights-product; de adapter heeft dus nooit tegen de echte API
gewerkt. Zijn 33 tests voerden het verzonnen schema in en zagen het gat daardoor
niet. Het was de zevende Critical, en weer zat hij in een naad: tussen de adapter
en de API waar hij tegen praat.

## Decision
De vluchtbron wordt **`IgnavFlightSource`**: `POST https://ignav.com/api/fares/one-way`
met de header `X-Api-Key`, uit env `IGNAV_API_KEY`. Bright Data en zijn tests zijn
verwijderd (`b5c74f5`). De poort `FlightPriceSource` blijft ongewijzigd; de
domeinmapping verhuisde vrijwel letterlijk mee.

Beslissingen van de eigenaar binnen de adapter:
- `requires_self_transfer: true` → optie afwijzen (geen doorgaand ticket).
- `price.status` → negeren; elke prijs accepteren.
- `price.amount` is in **hele euro's** en wordt omgerekend naar centen
  (`round($euros * 100)`).
- Maatschappijen en luchthavens buiten de toegestane set (zoals BT, TK, RIX, IST,
  die in de eerste echte respons zaten) → overslaan.

## Options Considered

### Option A: ignav (gekozen)
| Dimension | Assessment |
|-----------|------------|
| Complexity | Laag: gestructureerde JSON en tijden in UTC |
| Cost | 1000 gratis requests/maand, alleen geslaagde calls tellen, geen rate limit |
| Betrouwbaarheid | Getest tegen een **echt gemeten** respons (`fixtures/ignav-ams-hel.json`) |

**Pros:** een echte API met een echt schema; de adapter werd netto kleiner.
**Cons:** het tegoed is beperkt; een externe partij zonder SLA.

### Option B: Bright Data repareren (HTML van Google Flights scrapen)
| Dimension | Assessment |
|-----------|------------|
| Complexity | Hoog: HTML-parsing van een pagina die zonder aankondiging verandert |
| Cost | 5.000 credits/maand |

**Pros:** het account bestond al.
**Cons:** breekbaar, en de bestaande adapter moest toch volledig opnieuw.

## Trade-off Analysis
De eigenlijke les zat niet in de keuze van de leverancier: **een adapter moet
getest worden tegen een gemeten respons, niet tegen een aangenomen schema.** ignav
won omdat het een echte API is, en omdat er een echte respons als fixture naast ligt.

## Consequences
- Elke zoekopdracht kost 2 ignav-calls (HEL en TKU), en een retour 4 (zie ADR-0001).
- `IGNAV_API_KEY` hoort in `api/.env.local`, nooit in `api/.env` (die staat in
  git). In `APP_ENV=test` wordt `.env.local` niet geladen; geen enkele test raakt
  het netwerk.
- Herzien als het gratis tegoed structureel opraakt.
- Bij een nieuwe bron: eerst een echte respons vastleggen als fixture, dan pas de
  adapter schrijven.

## Action Items
1. [x] Gebouwd volgens `docs/plans/ignav-flightsource/plan.md`.
