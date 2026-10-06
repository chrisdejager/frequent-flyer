# ADR-0002: De uitgeschakelde zoekknop is de bescherming tegen races

**Status:** Accepted
**Date:** 2026-10-02
**Deciders:** eigenaar

## Context
Met twee parallelle zoekacties per retour (ADR-0001) kan in theorie een trage
oude zoekactie later binnenkomen dan een nieuwe en die overschrijven. Het
programmaontwerp (Gate 3) voorzag daarom een `requestId`-teller.

Bij het bouwen bleek de race via de UI niet te bereiken:
- de Zoek-knop staat op `:disabled="loading()"` zolang een van de kanten nog laadt;
- een uitgeschakelde standaard-submitknop blokkeert ook submit via Enter
  (HTML-spec, impliciete submission).

De test die de race aantoonde, vuurde `submit` rechtstreeks op het formulier af en
werkte daarmee om de echte UI heen. AGENTS.md zegt daarover: "Wantrouw een test
die om een probleem heen werkt in plaats van het te dichten."

## Decision
**Geen `requestId`.** De uitgeschakelde knop is de bescherming. Een test legt
vast dat de knop dicht blijft zolang een kant laadt en daarna weer opengaat:
`App.spec.ts`, test "de zoekknop is uitgeschakeld zolang een kant nog laadt".
Uit de mutatietest blijkt dat die test rood wordt zonder `:disabled`.

## Options Considered

### Option A: Uitgeschakelde knop, met test (gekozen)
| Dimension | Assessment |
|-----------|------------|
| Complexity | Geen extra state |
| Dekking | Alle huidige manieren om te zoeken |

**Pros:** geen code voor een pad dat niet bestaat; de test bewaakt het echte mechanisme.
**Cons:** het vangnet verdwijnt zodra er een tweede manier van zoeken bijkomt.

### Option B: `requestId`-teller
| Dimension | Assessment |
|-----------|------------|
| Complexity | Laag, maar wel extra state en twee guards (resultaat en `loading`) |
| Dekking | Ook toekomstige zoekpaden |

**Pros:** robuust als er later meer manieren komen om te zoeken.
**Cons:** beschermt nu tegen niets, en de enige test ervoor omzeilt de UI.

## Trade-off Analysis
B betaalt nu voor een probleem dat pas bestaat als iemand een nieuw zoekpad
toevoegt. A houdt de code eerlijk. Het risico is dat iemand later dat nieuwe pad
toevoegt zonder aan races te denken, en daarvoor is dit ADR er.

## Consequences
- **Herzien zodra een zoekactie kan starten terwijl een andere loopt**, bijvoorbeeld
  een geplande reis die direct zoekt, automatisch zoeken bij het wijzigen van een
  datum, of zoeken via de URL. Voeg dan `requestId` (of een `AbortController`) toe,
  met een test die via dat nieuwe pad de race aantoont.
- De knoptest niet versoepelen of verwijderen zonder dit ADR te vervangen.

## Action Items
1. [x] De knoptest staat in `web/src/components/__tests__/App.spec.ts`.
