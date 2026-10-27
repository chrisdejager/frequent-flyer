# Status: Deployment to the VPS

Decision: `docs/adr/0007-deploy-as-docker-images.md`.

## Steps
- [x] 1 — prod images run locally; ADR-0007
- [ ] 2 — `scripts/smoke-test.sh`, proven by mutation
- [ ] 3 — GitHub Actions: tests → build → smoke test → push to ghcr
- [ ] 4 — deploy (`workflow_dispatch` / `make deploy`) + example host nginx config in docs

## Owner decisions (2026-10-27)
- Images instead of Deployer.
- Build and test in GitHub Actions; images private on ghcr.io.
- Deploy triggered by hand, not on every push.
- Subdomain `flights.chrisdejager.nl`; host nginx already serves other
  subdomains with a wildcard certificate. Docker is installed; port 8081 is free.
- The pipeline must test that the images work, without touching the network
  (compose network `internal: true` during the smoke test).

## Measured 2026-10-27 (step 1, local prod stack on :8081)
- `/` 200 with the Vue `index.html`; `/assets/main-*.js` 200.
- `/api/search` without params 400 JSON; `/api/booking-link?ref=x` 400 JSON.
- `var/` writable by www-data; no `vendor/phpunit`; `lint:container` OK.
- Logs go to stderr, so `docker compose logs php` shows them.
- **Observation, not fixed:** with a wrong `IGNAV_API_KEY` ignav answers 401,
  logged as a warning, and `/api/search` returns 200 with `options: []`. The
  UI then looks like "no flights", indistinguishable from a broken key.
- `compose.prod.yaml` has project name `flights-prod`. With `flights` it
  shared the dev project name, and `up` silently replaced the dev `php` and
  `nginx` containers. Now dev (:8080) and prod (:8081) run side by side.
