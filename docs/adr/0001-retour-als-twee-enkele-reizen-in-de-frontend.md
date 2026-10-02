# ADR-0001: Retour = twee enkele reizen, samengesteld in de frontend

**Status:** Accepted
**Date:** 2026-10-02
**Deciders:** eigenaar

## Context
De eigenaar wil heen- en terugreis met twee datums op één scherm zien. Hij boekt
altijd twee losse enkele reizen ("het hoeft niet in 1 boeking"), dus retourtarieven
zijn niet nodig.

`GET /api/search?date=&direction=&traveller=` doet al precies één enkele reis,
inclusief regels, prijsberekening en rangschikking. De ignav-calls in de backend
lopen na elkaar; het parallelliseren van de `FlightPriceSource`-port is bewust
geparkeerd, omdat het de vorm van die port verandert.

Randvoorwaarden: ignav heeft 1000 gratis calls per maand, en geldbedragen zijn
altijd hele centen als int.

## Decision
Een retour is **twee onafhankelijke aanroepen van het bestaande `/api/search`**,
één per richting, die de browser parallel start (`Promise.allSettled`). De
frontend zet de uitkomsten in twee kolommen en telt `cost.total` (in centen) van
de gekozen optie per kant op. De backend krijgt geen retourbegrip.

De pure logica (richtingen, standaardkeuze, optellen, datumcontrole, koppelen van
geplande reizen) staat in `web/src/roundTrip.ts`, zonder Vue, zodat die los te
testen is.

## Options Considered

### Option A: Frontend roept `/api/search` twee keer aan (gekozen)
| Dimension | Assessment |
|-----------|------------|
| Complexity | Laag: geen backendwijziging |
| Cost | Gelijk aan twee losse zoekacties (2 ignav-calls per kant) |
| Latency | Twee kanten parallel; php-fpm `pm.max_children` = 5 is genoeg |
| Testbaarheid | Vitest met gemockte `api`; geen netwerk |

**Pros:** geen nieuwe API-oppervlakte; elke kant faalt los van de andere; een
andere optie kiezen kost geen call.
**Cons:** het totaal wordt in de browser berekend, buiten de domeinlaag.

### Option B: Nieuw endpoint `/api/round-trip`
| Dimension | Assessment |
|-----------|------------|
| Complexity | Middel: nieuwe query, handler, presenter en controller-tests |
| Cost | Gelijk |
| Latency | Slechter: 4 ignav-calls achter elkaar, zolang de port niet parallel is |
| Testbaarheid | WebTestCase door de echte container |

**Pros:** het totaal leeft in PHP, dicht bij de rest van de prijslogica.
**Cons:** trager; het dwingt het geparkeerde parallelliseren naar voren; het voegt
niets toe dat een optelling van twee ints niet al doet.

## Trade-off Analysis
Het enige argument voor B is dat de optelling dan in het domein zit. Maar een
retour is hier per definitie geen eigen product: er is geen retourtarief en geen
gezamenlijke boeking. De som van twee al-berekende totalen in centen is dan
presentatie, geen prijsregel. A is sneller en kleiner, en raakt geen code waar
eerdere Criticals zaten.

## Consequences
- Makkelijker: per kant los falen, los kiezen, en enkele reis (lege terugdatum = één call).
- Moeilijker: komt er ooit een prijsregel die **beide** kanten samen raakt (een
  retourkorting, een gedeelde treinprijs), dan hoort die in de backend en is dit
  ADR achterhaald.
- Herzien als de `FlightPriceSource`-port parallel wordt: dan wordt B qua snelheid
  gelijkwaardig.

## Action Items
1. [x] Gebouwd in zes slices; zie `docs/plans/heen-en-terug/`.
2. [ ] Bij een retourspecifieke prijsregel: een nieuw ADR dat dit vervangt.
