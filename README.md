# Flight finder

Finds the cheapest trip between two places, booked directly with the airline,
under conditions you set: which airlines, how many connections, how long a
connection may take, and when you want to depart. A place can have several
airports, and the finder searches all of them.

**Status: MVP with one demo route.** Amsterdam ⇄ Finland, with Helsinki
(direct flights) and Turku (one connection via Stockholm or Copenhagen), for
Finnair, SAS and KLM. One-way or round trip, a day forward or back per leg, and
a Book button that opens the airline's own booking page. Prices come from
[ignav](https://ignav.com). Baggage costs are deliberately not included yet
(ADR-0005).

**Where it is going:** saved routes with your own conditions, any airport,
users by invitation, and advice on which airline to pick given your loyalty
status. See the [roadmap](docs/roadmap.md).

- [Roadmap](docs/roadmap.md): phases, and what is still open
- [Architecture](docs/architecture.md): components and the search flow, as diagrams
- [Decisions](docs/adr/README.md): architecture decision records
- [Plans](docs/plans/README.md): features in progress; finished ones are in [history](docs/history/README.md)

## Setup

Copy `api/.env` to `api/.env.local` and fill in:

| Variable | Where from |
|---|---|
| `IGNAV_API_KEY` | ignav.com: 1,000 free requests per month, only successful calls count |

Without a key the app starts, and a search answers 503 "Flight search is not
configured: IGNAV_API_KEY is empty." A key that ignav rejects gives a 503 too.

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

## License

MIT, copyright (c) 2026 Chris de Jager. See [`LICENSE`](LICENSE).
