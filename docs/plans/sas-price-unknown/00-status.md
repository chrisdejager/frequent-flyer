# Status: SAS-prijs onbekend

**GEPARKEERD (eigenaar, 2026-10-03).** Niet gebouwd. De ignav-zoekrespons zegt niet
van wie de prijs komt; "prijs alleen van de maatschappij zelf" generiek toetsen kan
alleen met een booking-links-call per optie (6–8 extra per zoekopdracht). Eigenaar:
"klinkt bijna alsof we een andere prijs-API moeten hebben" — en gaat ervan uit dat
het een tijdelijk probleem van SAS is. Tot die tijd: de zelfgebouwde flysas.com-link
(ADR-0006, `8290829`) om de SAS-prijs met één klik handmatig te checken.
Ook overwogen en niet gekozen: een generieke lijst `CARRIERS_WITHOUT_OWN_PRICE=SK`.
Herstart dit plan als het probleem blijft (af en toe nameten: SAS-zoekprijs vs flysas.com).

**Snelle versie** (eigenaar, 2026-10-03): geen vier gates; productkeuzes uit
`01-product.md` + mockup gelden, ontwerp hieronder, twee slices.

## Slices
- [ ] Slice A — backend: schakelaar + "prijs onbekend" in `/api/search`
- [ ] Slice B — frontend: kaart, kop "Niet gerangschikt — prijs onbekend", totaalbalk

## Ontwerp
- **Schakelaar** `SAS_PRICES_TRUSTED` (env, bool, in `api/.env` op `0`), net als
  `TRAIN_COST_ENABLED` (ADR-0004) in `TotalCostCalculator` — één plek.
- **Domein:** `TotalCost` krijgt `bool $basePriceKnown` en `isRanked()` =
  `trainCostKnown && basePriceKnown`. `TotalCostCalculator::calculate` zet
  `basePriceKnown = false` als `itinerary->carrier === Carrier::Sas` en de
  schakelaar uit staat. `carrier` SK betekent: alle benen SK (adapterprioriteit
  KL > AY > SK); een gemengd KL+SK-ticket is KLM's eigen prijs en blijft gewoon.
- **Handler** rangschikt op `isRanked()` (nu: `trainCostKnown`).
- **Presenter:** `cost.priceKnown`; bij `false` zijn `basePrice`, `total` en
  `totalFormatted` **`null`** — de codeshare-prijs verlaat de server niet, dus de
  UI kán hem niet tonen.
- **Frontend:** `Cost` velden nullable + `priceKnown`; `isRanked(option)` in
  `roundTrip.ts` vervangt de losse `trainCostKnown`-checks (partition,
  defaultSelection, combine). Kaart: "prijs onbekend · zie flysas.com", rij
  "Vlucht: onbekend". Kop: "Niet gerangschikt — prijs onbekend". Totaalbalk:
  "onbekend" i.p.v. een bedrag als een gekozen kant geen prijs heeft.

## Tests (vooraf)
- `TotalCostCalculatorTest`: SK + uit → `basePriceKnown false`, niet ranked;
  SK + aan → known; AY/KL + uit → known.
- `SasPriceSwitchWiringTest` (echte container, beide standen van de env).
- `SearchControllerTest`: SAS-optie komt ná goedkopere én duurdere gerangschikte
  opties, met `priceKnown false` en `null`-bedragen; geen 37400 in de JSON.
- Frontend: `partition`/`defaultSelection`/`combine` met een SAS-optie; OptionCard
  toont "prijs onbekend" en geen "€"; LegColumn-kop; TotalBar "onbekend".

## Notes for a fresh session
- Aanleiding (2026-10-03, zie `docs/plans/booking-link/00-status.md`): ignav geeft
  bij SAS-vluchten de prijs van de KLM-codeshare als zoekprijs. Gemeten 26-11-2026:
  SK556+SK2728 € 374 in de app, € 193 op flysas.com; SK1556+SK2720 € 515 vs € 259.
  De zoekrespons verraadt het niet (zelfde `status: verified`).
- Besluit eigenaar: "nooit de codeshare-prijs, altijd bij de officiële maatschappij
  boeken" → alle SK-opties "prijs onbekend", zonder extra ignav-calls.
- Eigenaar denkt dat het **tijdelijk** is → schakelaar.
- De Boeken-knop bij SAS opent al een zelfgebouwde flysas.com-link (ADR-0006).
