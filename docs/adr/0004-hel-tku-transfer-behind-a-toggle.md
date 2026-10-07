# ADR-0004: HEL–TKU transfer behind a single toggle, off by default

**Status:** Accepted
**Date:** 2026-09-17 (retroactively documented on 2026-10-02)
**Deciders:** owner

## Context
A trip via Helsinki costs, in addition to the flight, the VR train HEL⇄TKU. That price comes
from `ApifyTrainPriceSource`, with ~20–50 Apify runs per month. The owner may want
a different source for this (there's a wish for bus and train data with prices) and
temporarily turns off the transfer.

The specification forbids an **invisible zero**: a total must not seem complete
if a cost line is missing.

## Decision
The env variable `TRAIN_COST_ENABLED` (default `0`) turns the transfer on or off.

- **Off:** train costs are €0, but a HEL option still gets **ranked normally**.
  The new field `trainCostIncluded=false` makes the UI show: "not included" and
  "Total excludes HEL→TKU transfer".
- `trainCostIncluded` is **separate from `trainCostKnown`**. If you set `trainCostKnown`
  to false, every Helsinki option ends up in the unranked bucket.
- The toggle is at **one place**: `TotalCostCalculator::usesTrainCost()`.
  `SearchFlightsHandler` checks it there before fetching a train price
  (`44453cc`). This way, a search with transfer off costs zero Apify runs.

## Options Considered

### Option A: Toggle with a visible label (chosen)
| Dimension | Assessment |
|-----------|------------|
| Complexity | Low: one env line and one extra field |
| Cost | 0 Apify runs when off |

**Pros:** can be turned back on in one line; the total is honest about what's missing.
**Cons:** Helsinki options look cheaper than they are (but with a label).

### Option B: Remove the train logic
**Pros:** less code.
**Cons:** restoring it requires a rebuild, while a new source is likely.

### Option C: Transfer off via `trainCostKnown=false`
**Cons:** all Helsinki options fall out of ranking, and that's exactly what you don't want.

## Trade-off Analysis
The pitfall was in the seam, not in the choice: the toggle was initially only in
`TotalCostCalculator`, while `SearchFlightsHandler` did the fetching. Every search
thus cost an Apify run whose result was immediately discarded. That was the eighth Critical.
The toggle therefore belongs with the component that determines costs, and the component
that fetches checks it there.

## Consequences
- To turn back on: `TRAIN_COST_ENABLED=1`. Both states have been tested through the real
  container, including the number of calls to the train source
  (`FixedTrainPriceSource::$callCount`).
- Pitfall in tests: in `SearchControllerTest` **restore** the env in `tearDown`,
  don't clear it. Clearing makes `%env(bool:...)%` throw, after which every subsequent test
  silently returns a 500.
- For a new transfer source (bus or train): the `TrainPriceSource` port remains
  the integration point; this ADR stays valid.
- For a round trip (ADR-0001) each side with a HEL option costs its own run once the
  toggle is on.

## Action Items
1. [ ] For a new transfer source: an ADR for that source, and reconsider the default.
