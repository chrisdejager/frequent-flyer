# ADR-0005: Bagagekosten tellen niet mee

**Status:** Accepted
**Date:** 2026-09-21 (retroactief vastgelegd op 2026-10-02)
**Deciders:** eigenaar

## Context
Het totaal rekende eerst per reiziger bagagekosten mee, op basis van per
maatschappij vastgelegde loyaliteits- en tariefregels (`BaggagePolicy`,
`BaggageEntitlement`, `FareUpgradeRates`, `MissingFareUpgradeRate`,
`LoyaltyStatus`). De eigenaar meldde een fout: ook bij een expliciet gekozen
reiziger kreeg "chris" toch bagagekosten mee. De complexiteit van die
per-maatschappij-logica was de bron van de fout.

## Decision
**Bagagekosten worden niet berekend.** `TotalCost::total()` is `basePrice + trainCost`.
`Traveller` verliest de loyaliteitsmap en `statusFor()`, `LoyaltyStatus` vervalt,
en `TotalCostCalculator::calculate()` heeft geen `Traveller` meer nodig. De
configuratie `FARE_UPGRADE_AY/KL` is weg (`e6d388a`).

## Options Considered

### Option A: Bagage eruit (gekozen)
| Dimension | Assessment |
|-----------|------------|
| Complexity | Laag: een hele subdomeinlaag verdwijnt |
| Correctheid | Bewezen gelijk totaal voor beide reizigers |

**Pros:** de bug is per constructie weg; het totaal is eenvoudig uit te leggen.
**Cons:** het totaal is lager dan wat je echt betaalt als je ruimbagage nodig hebt.

### Option B: De bagageberekening repareren
**Pros:** een vollediger totaal.
**Cons:** veel regels per maatschappij en per status, dynamische KLM-prijzen
(alleen "indicatief"), en de bron van de fout zat juist in die complexiteit.

## Trade-off Analysis
Voor twee reizigers die zelf boeken, weegt een eenvoudig en eerlijk totaal zwaarder
dan een vollediger maar foutgevoelig totaal. Reiziger kiezen blijft bestaan in de
UI, maar heeft op de prijs geen invloed meer.

## Consequences
- Tests leggen vast dat Chris en Partner hetzelfde totaal betalen:
  `test_chris_and_partner_pay_the_same_total_since_baggage_is_not_priced` en
  `test_chris_and_partner_get_the_same_total_on_finnair`.
- **Terughalen** kan uit de git-historie vóór `e6d388a`. Doe dat dan met een
  nieuw ADR dat dit vervangt, en met een test die de oorspronkelijke fout (een
  verkeerde reiziger krijgt bagage mee) eerst rood laat zien.
- De afwijzing van `requires_self_transfer` in ignav (ADR-0003) was destijds deels
  verantwoord met "de bagagerekensom gaat uit van één ticket". Die som bestaat niet
  meer. Afwijzen blijft wel zinvol om een andere reden: geen doorgaand ticket
  betekent risico bij een gemiste overstap. Wie daaraan wil tornen, moet dat
  expliciet afwegen.

## Action Items
1. [ ] Overweeg of de reizigerskeuze in de UI nog nodig is nu die de prijs niet beïnvloedt.
