# Status: Deployment to the VPS

Decision: `docs/adr/0007-deploy-as-docker-images.md`.

## Steps
- [x] 1 — prod images run locally; ADR-0007
- [x] 2 — `scripts/smoke-test.sh`, proven by mutation; clear 503 for a missing ignav key
- [x] 3 — GitHub Actions (`.github/workflows/ci.yml`): tests → build → smoke test → push to ghcr
- [x] 4 — `scripts/deploy.sh` + `docs/deployment.md` (runbook, host nginx config)
- [ ] 5 — one-time server setup by the owner, first deploy

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
- **Observation (fixed for an empty key in e5112f7):** with a wrong `IGNAV_API_KEY` ignav answers 401,
  logged as a warning, and `/api/search` returns 200 with `options: []`. The
  UI then looks like "no flights", indistinguishable from a broken key.
- `compose.prod.yaml` has project name `flights-prod`. With `flights` it
  shared the dev project name, and `up` silently replaced the dev `php` and
  `nginx` containers. Now dev (:8080) and prod (:8081) run side by side.

## Owner decisions (2026-10-27, later)
- A missing ignav key must give a clear message: `/api/search` now answers
  503 "Flight search is not configured: IGNAV_API_KEY is empty." (e5112f7).
  A key that ignav *rejects* (401) gives 503 "… ignav rejected IGNAV_API_KEY
  (HTTP 401)." as well (owner, same day). Booking links with a bad key
  still give their generic 502.
- The production images must not run locally by default. Only the smoke test
  starts them (project `flights-smoke`) and always tears them down.

## Smoke test (step 2)
`scripts/smoke-test.sh` + `compose.smoke.yaml` + `docker/smoke/app.env`.
Runs the prod images on a compose network with `internal: true`; checks run
via `docker compose exec nginx curl`, no port is published. `APP_ENV_FILE`
in `compose.prod.yaml` selects the env file (default `app.env`).

Mutation-proven 2026-10-27, each breakage turns the script red:

| Breakage | Caught by |
|---|---|
| nginx `root` for the SPA wrong | `GET /` 500 |
| Vue build not copied into the image | `GET /` lacks `<div id="app">`, no asset |
| fastcgi `SCRIPT_FILENAME` wrong | all `/api` checks 404 |
| `var/` not chowned | var/ writable check |
| `composer install` with dev deps | no-dev-deps check |
| typo in `%env(IGNAV_API_KEY)%` in services.yaml | `/api/search` 500 |
| network not internal | no-internet check |

Not caught, by design: an empty `APP_SECRET` (nothing in the app uses it yet).

## CI workflow (step 3)
`.github/workflows/ci.yml`, on push to main and on pull requests.
- `test`: the dev containers from `compose.yaml` (project `flights-ci`):
  composer install, PHP suite with `--network none`, frontend test + build.
- `images` (after `test`): build `compose.prod.yaml` with `TAG=<full sha>`,
  run `scripts/smoke-test.sh`, and only on main push `:<sha>` and `:latest`
  to ghcr.io with `GITHUB_TOKEN` (`packages: write`).
- Both Dockerfiles carry `org.opencontainers.image.source` pointing at the
  repo, so a package created by the workflow is linked to it.

Verified 2026-10-27 without pushing: actionlint (incl. shellcheck) clean;
both jobs' commands run in a fresh clone: 185 PHP offline, 96 frontend,
build, smoke test 9/9 against `TAG=ci-local`. The push step itself has not
run yet.

## CI, first runs (2026-10-27)
- Run for d5187ef failed at the push: `docker compose push` loads
  `env_file: app.env`, which only exists on the server. Fixed in bdbddae
  (`docker push` per image). Run for bdbddae green (owner).
- No images exist for d5187ef; the first deployable sha is bdbddae.

## Deploy (step 4)
- Local script, no deploy secrets in GitHub (manual trigger, owner decision).
  A `workflow_dispatch` around it stays possible.
- `scripts/deploy.sh [commit]` → refuses commits not on origin/main, uploads
  that commit's `compose.prod.yaml` as `.new`, runs
  `scripts/deploy-on-server.sh` over ssh: pull (abort = nothing changed),
  move compose file in place, `up -d`, `TAG=<sha>` in the server's `.env`,
  health check on 127.0.0.1:8081 (`/` 200, `/api/search` 400).
- Tested locally 2026-10-27: missing app.env stops with a pointer to the
  runbook; failed pull (no ghcr login → `unauthorized`, which also confirms
  the images are private) leaves `.env`, compose file and containers
  untouched; no DEPLOY_HOST, a commit not on main, an unknown ref and an
  unreachable host each stop with a clear message. **The success path
  (pull, up, health check) has not run yet**: needs ghcr access.
