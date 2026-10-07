# ADR-0005: Baggage costs excluded

**Status:** Accepted
**Date:** 2026-09-21 (retroactively documented on 2026-10-02)
**Deciders:** owner

## Context
The total previously included baggage costs per traveler, based on airline-specific
loyalty and fare rules (`BaggagePolicy`, `BaggageEntitlement`, `FareUpgradeRates`,
`MissingFareUpgradeRate`, `LoyaltyStatus`). The owner reported a bug: even with
an explicitly chosen traveler, "chris" still got baggage costs applied. The complexity
of that per-airline logic was the source of the bug.

## Decision
**Baggage costs are not calculated.** `TotalCost::total()` is `basePrice + trainCost`.
`Traveller` loses the loyalty map and `statusFor()`, `LoyaltyStatus` is removed,
and `TotalCostCalculator::calculate()` no longer needs `Traveller`. The
configuration `FARE_UPGRADE_AY/KL` is gone (`e6d388a`).

## Options Considered

### Option A: Remove baggage (chosen)
| Dimension | Assessment |
|-----------|------------|
| Complexity | Low: a whole subdomain layer disappears |
| Correctness | Proven equal total for both travelers |

**Pros:** the bug is gone by construction; the total is simple to explain.
**Cons:** the total is lower than what you actually pay if you need checked baggage.

### Option B: Fix the baggage calculation
**Pros:** a more complete total.
**Cons:** many rules per airline and per status, dynamic KLM prices (only "indicative"),
and the bug was rooted in exactly that complexity.

## Trade-off Analysis
For two travelers booking themselves, a simple and honest total outweighs
a more complete but error-prone total. The choice of traveler stays in the UI
but no longer affects the price.

## Consequences
- Tests verify that Chris and Partner pay the same total:
  `test_chris_and_partner_pay_the_same_total_since_baggage_is_not_priced` and
  `test_chris_and_partner_get_the_same_total_on_finnair`.
- **Can be restored** from git history before `e6d388a`. Do that with a
  new ADR that replaces this one, and with a test that shows the original bug
  (wrong traveler gets baggage) failing first.
- The rejection of `requires_self_transfer` in ignav (ADR-0003) was partly
  justified with "the baggage sum assumes one ticket". That sum no longer exists.
  Rejection still makes sense for another reason: no through ticket means risk if
  you miss a connection. Anyone who wants to change that must weigh it explicitly.

## Action Items
1. [ ] Consider whether the traveler choice in the UI is still needed now that it doesn't affect price.
