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

## Running

```bash
docker compose exec -T php php bin/phpunit          # PHP suite
docker compose run --rm node npm run test           # frontend
docker compose run --rm node npm run build
docker compose up                                   # app at http://localhost:8080
```

Proving that no test touches the network:

```bash
docker run --rm --network none -v "$PWD/api:/app" -w /app flights-php:latest php bin/phpunit
```

The PHPUnit config is `phpunit.dist.xml`; the working directory in the container is `/app` (= `api/`).

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
