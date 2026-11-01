# Leiden ↔ Turku flight finder

Finds the cheapest trip between Leiden and Turku. The total is the ticket price
plus the VR train Helsinki⇄Turku where applicable. HEL<>AMS shows only direct
flights, TKU<>AMS only flights with exactly 1 connection.

Baggage costs are deliberately not included (simplification) — why, and how to
bring them back, is in `docs/adr/0005-exclude-baggage-costs.md`.

## Language

Everything in the repo is in English: code, comments, UI copy, docs and commit
messages. Historical logs (`docs/plans/*/plan.md`, `progress.md`) are still in
Dutch and are not translated.

## Read this first in a new session

- **`docs/plans/README.md`** — where the work stands, what is open, and which
  decisions the owner has made. Start here.
- `docs/plans/<feature>/` — one folder per feature: `00-status.md` (state),
  `01-product.md` through `04-slices.md`, mockups and measurements.
  New features go here too.
- `docs/adr/` — lasting decisions.
  Change a decision with a new ADR; never rewrite an old one.
- `docs/plans/flight-finder-phase-1/` — intake, spec, plan and log of phase 1.
- `docs/architecture.md` — components and the main search flow, as diagrams.

## Running

The Makefile is the interface; every tool runs in a container (`make help`).

```bash
make up              # app at http://localhost:8080
make test            # PHP suite + frontend suite
make phpunit         # PHP suite only
make test-offline    # PHP suite with --network none: proves no test touches the network
make web-test        # frontend suite only
make build           # frontend production build
make smoke           # build the production images and smoke-test them without internet
```

The PHPUnit config is `phpunit.dist.xml`; the working directory in the container is `/app` (= `api/`).

## Stack choices for this project

The owner's default stack (PHP/Symfony, DDD) leaves a few things per project.
Here they are decided; don't reopen them without the owner:

- **Frontend: Vue 3** (`web/`). **Backend: Symfony** (`api/`).
- **No database yet.** Nothing is stored; `PriceHistory` is a port with a
  `NullPriceHistory`. A database may come later, as its own feature.
- **No commands yet.** The API only reads: plain `GET` endpoints calling a
  handler, no command bus. Commands may come later, as their own feature.
- **Tests are a pyramid:** mostly PHPUnit (including API tests through the real
  container), far fewer Behat scenarios — only features a non-technical person
  should be able to read — and, later, a handful of Playwright flows. Behat is
  not set up yet: see `docs/plans/behat-feature-tests/`.

## Constraints

- PHP 8.4 / Symfony 7.4 LTS + Vue 3. **No Python** — hard requirement.
- `declare(strict_types=1)` everywhere; `src/Domain/**` is framework-free (an
  architecture test checks this).
- Money amounts always in whole cents as int.
- Airlines AY/SK/KL; airports AMS/HEL/TKU/ARN/CPH; connection ≤120 minutes.
- **No test may touch the network.** External credits are small: Bright
  Data 5,000 credits/month, Apify ~20–50 runs/month.

## How this codebase learned to work

Three final reviews found six serious bugs. **None was inside a single
component** — all were in the seam between them: adapter versus rule, domain
versus wiring, caller versus mapper.

- Measure behaviour through the **real container** (`WebTestCase`), not around
  the configuration. A factor-100 error in a parameter stayed invisible because
  both the tests and a manual check passed the value in themselves.
- Distrust a test that works around a problem instead of closing it.
- A fix without a test that fails *without* that fix is not a fix. Prove it with
  a mutation.
- Verify review findings empirically before accepting *or* rejecting them.
