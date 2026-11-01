# ADR-0008: Remove the HEL–TKU train

**Status:** Accepted
**Date:** 2026-11-01
**Deciders:** owner
**Supersedes:** [ADR-0004](0004-hel-tku-transfer-behind-a-toggle.md)

## Context
The flight finder becomes general (roadmap). How you get to and from the
airport is out of scope for now (`plans/general-flight-finder/00-status.md`).
The train leg on the demo route was behind a toggle that had been off since
September; the code for it still cost tests, wiring and a second external
account.

## Decision
The train goes, all of it: the `TrainPriceSource` port and its adapter, the
`TRAIN_COST_ENABLED` and `APIFY_TOKEN` variables, and the cost fields
`trainCost`, `trainCostKnown`, `trainCostIncluded` and `indicative` in API and
UI. A total is now the flight price alone (`TotalCost::total()`), and every
option is ranked by it; the "unranked" group in the UI is gone.

`RoutePattern::ViaHelsinkiWithTrain` becomes `RoutePattern::Helsinki`
(`'helsinki'` in the API). The demo-route rules stay as they are: Helsinki
direct only, the Helsinki time window, Turku with one connection.

## Consequences
- Less code and no second external account to keep funded.
- Ground transport, if it ever returns, is a new feature with its own ADR; the
  old code is in git history.
- A future "price unknown" case (`plans/sas-price-unknown/`) brings back its
  own ranked/unranked split.
