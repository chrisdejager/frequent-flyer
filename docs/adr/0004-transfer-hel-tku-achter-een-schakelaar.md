# ADR-0004: Transfer HEL–TKU achter één schakelaar, standaard uit

**Status:** Accepted
**Date:** 2026-09-17 (retroactief vastgelegd op 2026-10-02)
**Deciders:** eigenaar

## Context
Een reis via Helsinki kost naast de vlucht ook de VR-trein HEL⇄TKU. Die prijs komt
uit `ApifyTrainPriceSource`, met ~20–50 Apify-runs per maand. De eigenaar wil
daar mogelijk een andere bron voor (er ligt een wens voor bus- en treindata met
prijzen) en zet de transfer daarom tijdelijk uit.

De specificatie verbiedt een **onzichtbare nul**: een totaal mag niet compleet
lijken als er een kostenpost ontbreekt.

## Decision
De env-variabele `TRAIN_COST_ENABLED` (standaard `0`) zet de transfer aan of uit.

- **Uit:** de treinkosten zijn €0, maar een HEL-optie blijft gewoon **gerangschikt**.
  Het nieuwe veld `trainCostIncluded=false` laat de UI tonen: "niet meegerekend" en
  "Totaal is exclusief transfer HEL→TKU".
- `trainCostIncluded` staat **los van `trainCostKnown`**. Zou je `trainCostKnown`
  op false zetten, dan komt elke Helsinki-optie in de ongerangschikte bak terecht.
- De schakelaar staat op **één plek**: `TotalCostCalculator::usesTrainCost()`.
  `SearchFlightsHandler` vraagt het daar op voordat hij een treinprijs ophaalt
  (`44453cc`). Zo kost een zoekopdracht met de transfer uit geen Apify-run.

## Options Considered

### Option A: Schakelaar met een zichtbaar label (gekozen)
| Dimension | Assessment |
|-----------|------------|
| Complexity | Laag: één env-regel en één extra veld |
| Cost | 0 Apify-runs bij uit |

**Pros:** in één regel terug te zetten; het totaal is eerlijk over wat ontbreekt.
**Cons:** de Helsinki-opties zien er goedkoper uit dan ze zijn (wel met label).

### Option B: De treinlogica verwijderen
**Pros:** minder code.
**Cons:** terughalen kost een herbouw, terwijl een nieuwe bron waarschijnlijk is.

### Option C: Transfer uit via `trainCostKnown=false`
**Cons:** alle Helsinki-opties vallen uit de rangschikking, en dat is precies wat
je niet wilt.

## Trade-off Analysis
De valkuil zat in de naad, niet in de keuze: de schakelaar stond eerst alleen in
`TotalCostCalculator`, terwijl `SearchFlightsHandler` het ophalen deed. Elke
zoekopdracht kostte daardoor een Apify-run waarvan de uitkomst direct werd
weggegooid. Dat was de achtste Critical. De schakelaar hoort daarom bij de
component die de kosten bepaalt, en de component die ophaalt vraagt het daar op.

## Consequences
- Terugzetten: `TRAIN_COST_ENABLED=1`. Beide standen zijn getoetst door de echte
  container, inclusief het aantal aanroepen van de treinbron
  (`FixedTrainPriceSource::$callCount`).
- Valkuil in tests: in `SearchControllerTest` de env in `tearDown` **herstellen**,
  niet wissen. Wissen laat `%env(bool:...)%` gooien, waarna elke volgende test stil
  een 500 geeft.
- Bij een nieuwe transferbron (bus of trein): de poort `TrainPriceSource` blijft
  het aanknopingspunt; dit ADR blijft gelden.
- Bij een retour (ADR-0001) kost elke kant met een HEL-optie een eigen run zodra de
  schakelaar aan staat.

## Action Items
1. [ ] Bij een nieuwe transferbron: een ADR voor die bron, en de standaard opnieuw overwegen.
