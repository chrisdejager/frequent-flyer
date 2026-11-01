# Flight finder

A general flight finder: the cheapest trip between two places, booked directly
with the airline, under conditions the user sets. Today it is an MVP with one
**demo route** that is still code: AMS ⇄ HEL shows only direct flights,
AMS ⇄ TKU only flights with exactly 1 connection.

Baggage costs are deliberately not included (simplification) — why, and how to
bring them back, is in `docs/adr/0005-exclude-baggage-costs.md`.

## Language

Everything in the repo is in English: code, comments, UI copy, docs and commit
messages. Historical logs (`docs/history/*/plan.md`, `progress.md`) are still in
Dutch and are not translated.

## Read this first in a new session

- **`docs/roadmap.md`** — the main plan: phases from the demo route to the
  general flight finder, and what is still open. Start here.
- `docs/plans/README.md` — features in progress, one folder each:
  `00-status.md` (state and owner decisions), `01-product.md` through
  `04-slices.md`, mockups and measurements. New features go here too.
- `docs/plans/general-flight-finder/00-status.md` — the owner's decisions
  behind the roadmap.
- `docs/adr/` — lasting decisions.
  Change a decision with a new ADR; never rewrite an old one.
- `docs/architecture.md` — components and the main search flow, as diagrams.
- `docs/history/` — finished plans and the session handoff log of the MVP.
  A finished feature folder moves there from `docs/plans/`.

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

- PHP 8.4 / Symfony 7.4 LTS + Vue 3.
- `declare(strict_types=1)` everywhere; `src/Domain/**` is framework-free (an
  architecture test checks this).
- Money amounts always in whole cents as int.
- Demo route, until roadmap phase 2: airlines AY/SK/KL; airports
  AMS/HEL/TKU/ARN/CPH; connection ≤120 minutes.
- **No test may touch the network.** External credits are small: ignav
  1,000 requests/month.

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
