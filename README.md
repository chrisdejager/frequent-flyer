# Leiden ↔ Turku flight finder

Searches for the cheapest travel options between Leiden and Turku, with a total price
that includes the VR train (baggage costs intentionally excluded, see ADR-0005).

- [Status](docs/plans/README.md)
- [Design phase 1](docs/plans/flight-finder-phase-1/spec.md)
- [Implementation plan phase 1](docs/plans/flight-finder-phase-1/plan.md)

## Setup

Copy `api/.env` to `api/.env.local` and fill in:

| Variable | Where from |
|---|---|
| `BRIGHTDATA_TOKEN` | brightdata.com — 5,000 free credits per month |
| `BRIGHTDATA_ZONE` | name of your SERP zone |
| `APIFY_TOKEN` | apify.com — $5 free credit per month |
| `FARE_UPGRADE_AY` | Finnair Light→Plus in euros — **16** (fixed rate) |
| `FARE_UPGRADE_KL` | KLM Light→Plus in euros — **25** (estimate; dynamically priced) |

Without `FARE_UPGRADE_AY` and `FARE_UPGRADE_KL` **the first search request fails**
with a 500 — missing variables return `Environment variable not found`, empty values
return `Non-numeric env var ... cannot be cast to float`. This is intentional:
calculating with €0 would make Light fares look systematically too cheap.

Note that the container itself does start; you only notice a missing value when
you run the first search, not during deployment. After a deploy, run one search
to verify that the configuration is complete.

## Running locally

```bash
make up
```

Open http://localhost:8080. `make help` lists all targets.

## Testing

```bash
make test            # PHP suite + frontend suite
make test-offline    # PHP suite without network access
```

How the parts fit together: [`docs/architecture.md`](docs/architecture.md).

## To the VPS

Production runs from two images with the code baked in (`docker/php/Dockerfile`
target `prod`, `docker/nginx/Dockerfile`); see ADR-0007. Deploying, server
setup and rollback: [`docs/deployment.md`](docs/deployment.md). Locally they don't run
by default: check them with the smoke test, which starts them without internet
access and tears them down again.

```bash
docker compose -f compose.prod.yaml build
scripts/smoke-test.sh
```

Only when you want to click through the production build yourself:

```bash
cp app.env.example app.env        # fill in; git-ignored
docker compose -f compose.prod.yaml up -d     # http://127.0.0.1:8081
docker compose -f compose.prod.yaml down      # when done
```
