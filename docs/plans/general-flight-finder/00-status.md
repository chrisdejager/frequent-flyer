# Status: General flight finder

From a Leiden ⇄ Turku tool to a general flight finder, open source.
Roadmap: [`roadmap.md`](roadmap.md). Nothing is built yet.

## Owner decisions (intake, 1 Nov 2026)

**Product**
- The core is **both together**: searching with your own conditions, and
  advice based on your loyalty status. Neither is an add-on.
- A route goes **from somewhere to somewhere**: one route can have several
  origin and several destination airports. That is what makes the app useful.
  How you get to and from the airport is **out of scope** for now.
- **Booked with the airline itself is a hard filter**: only tickets sold
  directly by the airline. No OTAs, no self-transfer combinations.
- Conditions per route: airlines (allow/deny list), connections (count,
  min/max connection time, allowed/forbidden connection airports), time
  windows per direction, trip form (one-way, round trip as two one-ways,
  real return ticket).
- **Account defaults plus per-route overrides** for conditions.
- **Max airports per side of a route** (proposal: 3). The UI shows up front
  how many calls a search costs; the per-user limit counts calls.
- A saved route: **search again with one click**, you pick the dates.
  **Price alerts** ("time to book", "special offer") are wanted, but as a
  **future** feature. Price history is not asked for on its own.
- Loyalty: **tier plus progress**, entered by the user (tier, points or
  levels, qualification date). The app does not compute points per flight.
- Programs: owner unsure; leaning to a **fixed catalogue** (simpler for the
  user). Alliance-level benefits still open.
- Advice: **price including status benefit** and **keeping your status**.
  Money-versus-status trade-off: not decided yet.
- **The HEL ⇄ TKU train is removed**, Apify adapter included. Turku becomes
  the route {TKU, HEL} without ground transport. It stays in git history.

**Users**
- **Small circle, own users, by invitation** — for now.
- **One account = one traveller.** Travelling together: everyone searches on
  their own; no linking of companions.
- **Email + password** login (Symfony Security), with password reset.
- **API key: the instance's key with a fallback**: the admin's ignav key is
  the default, a user may enter their own; a per-user limit protects the
  admin's credits.

**Technology and open source**
- **PostgreSQL + Doctrine**, mapping in Infrastructure (domain stays free of
  the framework). Ends "no database yet" in AGENTS.md.
- Writes come in, so "no commands yet" ends too (how: open question).
- **Repo public now.** MIT license (`LICENSE`). Docs may be public.
- **Self-hosting: best effort** — compose file, example env, short install
  guide. No support, no versioned releases.
- ghcr images stay private.
- No IDE-specific entries in `.gitignore`: that is each developer's own job.
</content>
</invoke>
