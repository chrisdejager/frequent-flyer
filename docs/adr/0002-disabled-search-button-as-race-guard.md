# ADR-0002: The disabled search button is the protection against races

**Status:** Accepted
**Date:** 2026-10-02
**Deciders:** owner

## Context
With two parallel search actions per round trip (ADR-0001), in theory a slow
old search action could arrive later than a new one and overwrite it. The
program design (Gate 3) therefore provided a `requestId` counter.

During implementation, the race turned out to be unreachable via the UI:
- the Search button is set to `:disabled="loading()"` as long as either side is still loading;
- a disabled default submit button also blocks submit via Enter
  (HTML spec, implicit submission).

The test that demonstrated the race fired `submit` directly on the form and
worked around the real UI. AGENTS.md says about this: "Distrust a test
that works around a problem instead of closing it."

## Decision
**No `requestId`.** The disabled button is the protection. A test verifies
that the button stays disabled as long as one side is loading and then opens again:
`App.spec.ts`, test "the search button is disabled while one side is still loading".
Mutation testing shows that this test fails without `:disabled`.

## Options Considered

### Option A: Disabled button, with test (chosen)
| Dimension | Assessment |
|-----------|------------|
| Complexity | No extra state |
| Coverage | All current ways to search |

**Pros:** no code for a path that doesn't exist; the test guards the real mechanism.
**Cons:** the safeguard disappears as soon as there's a second way to search.

### Option B: `requestId` counter
| Dimension | Assessment |
|-----------|------------|
| Complexity | Low, but adds extra state and two guards (result and `loading`) |
| Coverage | Also future search paths |

**Pros:** robust if more search methods come later.
**Cons:** protects nothing now, and the only test for it works around the UI.

## Trade-off Analysis
B pays now for a problem that only exists if someone adds a new search path.
A keeps the code honest. The risk is that someone later adds that new path
without thinking about races, and that's what this ADR is for.

## Consequences
- **Revisit as soon as one search action can start while another is running**, for example
  a booked trip that searches directly, automatic search on date change, or search via URL.
  Then add `requestId` (or an `AbortController`), with a test that demonstrates the race via that new path.
- Don't relax or remove the button test without replacing this ADR.

## Action Items
1. [x] The button test is in `web/src/components/__tests__/App.spec.ts`.
