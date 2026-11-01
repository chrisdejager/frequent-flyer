# ADR-0001: Round trip = two one-way trips, composed in the frontend

**Status:** Accepted
**Date:** 2026-10-02
**Deciders:** owner

## Context
The owner wants to see outbound and return trips with two dates on one screen. He always
books two separate one-way trips ("doesn't need to be in 1 booking"), so round-trip fares
are not needed.

`GET /api/search?date=&direction=&traveller=` already does exactly one one-way trip,
including rules, price calculation, and ranking. The ignav calls in the backend
run sequentially; parallelizing the `FlightPriceSource` port is intentionally
deferred because it changes the port's shape.

Constraints: ignav has 1000 free calls per month, and money amounts are
always whole cents as int.

## Decision
A round trip is **two independent calls to the existing `/api/search`**,
one per direction, which the browser starts in parallel (`Promise.allSettled`). The
frontend displays the results in two columns and sums `cost.total` (in cents) of
the chosen option per side. The backend has no round-trip concept.

The pure logic (directions, default choice, summing, date validation, pairing of
booked trips) lives in `web/src/roundTrip.ts`, without Vue, so it can be tested
separately.

## Options Considered

### Option A: Frontend calls `/api/search` twice (chosen)
| Dimension | Assessment |
|-----------|------------|
| Complexity | Low: no backend change |
| Cost | Same as two separate searches (2 ignav calls per side) |
| Latency | Two sides in parallel; php-fpm `pm.max_children` = 5 is enough |
| Testability | Vitest with mocked `api`; no network |

**Pros:** no new API surface; each side fails independently; choosing a different option costs no call.
**Cons:** the total is calculated in the browser, outside the domain layer.

### Option B: New endpoint `/api/round-trip`
| Dimension | Assessment |
|-----------|------------|
| Complexity | Medium: new query, handler, presenter, and controller tests |
| Cost | Same |
| Latency | Worse: 4 ignav calls in sequence, as long as the port is not parallel |
| Testability | WebTestCase through the real container |

**Pros:** the total lives in PHP, close to the rest of the pricing logic.
**Cons:** slower; it forces the deferred parallelization forward; it adds nothing that a sum of two ints doesn't already do.

## Trade-off Analysis
The only argument for B is that the sum would then live in the domain. But a
round trip is by definition not its own product here: there is no round-trip fare and no
joint booking. The sum of two already-calculated totals in cents is then
presentation, not a pricing rule. A is faster and smaller, and doesn't touch code where
earlier Criticals were found.

## Consequences
- Easier: each side can fail independently, choose independently, and single trip (empty return date = one call).
- Harder: if a pricing rule ever **affects both** sides together (a round-trip discount, a shared train price), that belongs in the backend and this
  ADR is superseded.
- Reconsider if the `FlightPriceSource` port becomes parallel: then B becomes equivalent in speed.

## Action Items
1. [x] Built in six slices; see `docs/history/round-trip/`.
2. [ ] For a round-trip-specific pricing rule: a new ADR that replaces this one.
