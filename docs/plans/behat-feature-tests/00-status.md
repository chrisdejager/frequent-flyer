# Status: Behat feature tests

Not started. Agreed with the owner on 1 Nov 2026 as a work item; nothing is
designed or built yet.

- Gate 1 — Product: pending
- Gate 2 — Architecture: pending
- Gate 3 — Program Design: pending
- Gate 4 — Slice plan: pending

## Scope

Set up Behat with a single `api` suite and write a few feature scenarios that a
non-technical person can read and confirm. Behat is the thin middle of the test
pyramid (AGENTS.md, "Stack choices"): PHPUnit stays the place for rules and
edge cases.

Candidate scenarios, to be chosen at Gate 1:

- The cheapest trip Leiden → Turku, total = ticket + train where applicable.
- An option with an unknown train price stays visible and comes last.
- With the HEL–TKU transfer off, a Helsinki option says "not included".

## Notes for a fresh session

- New dependencies (`behat/behat`, `friends-of-behat/symfony-extension`): justify
  them at Gate 2.
- Scenarios run in-process against the API with the test doubles in
  `api/tests/Double`; no scenario may touch the network (`make test-offline`
  must cover Behat too).
