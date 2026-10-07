# Architecture: Outbound and return on one screen

## Fit
**Only the frontend (`web/`) changes. The backend remains unchanged.**

A round trip consists here of two separate one-way trips (as the owner decided in Gate 1), and `GET /api/search` already does exactly one one-way trip:
date + direction + traveller. The frontend therefore calls it twice, once per side, and puts the results side by side.

- `App.vue`: the form gets a start country and two dates. The results
  come in two columns, with the total bar above them.
- `OptionCard.vue`: becomes selectable (selected or not) and stays otherwise the same.
- `api.ts`: `search()` stays as it is.
- Backend: `SearchController`, `SearchFlightsHandler`, the presenter and the
  rules/calculator remain unchanged.

**Rejected alternative: a new `/api/round-trip` endpoint.**
- In PHP the ignav calls happen in sequence. A round trip would then be four calls in sequence, versus two times two in parallel from the browser.
- Parallelizing the `FlightPriceSource` port is intentionally parked
  (see HANDOVER).
- It yields nothing the frontend cannot do itself: the total is just two
  amounts in cents added together.

## Endpoints
None new. Existing ones are reused:
- `GET /api/search?date=&direction=&traveller=`: once for the outbound trip, and
  once for the return trip if there is a return date.
- `GET /api/trips`: unchanged. A scheduled trip fills the outbound or the
  return date, depending on the direction relative to the chosen start country.

## Data
No database, no new fields. Only state in the browser:
- the start country (`nl` | `fi`), the outbound date and an optional return date
- per side its own `SearchResult | null`, `error` and `loading`
- per side the index of the chosen option (by default the cheapest
  ranked option)

## Flow
1. The user enters: start country, outbound date, possibly a return date, and the
   traveller. Then he clicks Search.
2. First the frontend checks that the return date is not before the outbound date.
   If not, a message follows and nothing is called.
3. The directions follow from the start country. For Netherlands, outbound is `nl-fi` and
   return is `fi-nl`; for Finland it's the opposite.
4. Both `search()` calls are started **in parallel** (`Promise.allSettled`).
   Each side fills its own result or error message, independently of the
   other side.
5. Per side, by default the first ranked option is chosen; that is the
   cheapest, because the backend already sorts by total.
6. The total bar adds `cost.total` (in cents) of the chosen option(s). If
   one side has no choice (no options, error or no return date), only the
   other side counts, and that is noted there.
7. When the user clicks a different option, the choice for that side changes
   and the total is recalculated. No new API call follows.

## External
- **ignav:** the number of calls per round trip equals two separate searches
  (2 per side). The backend continues using the same `IGNAV_API_KEY`; that
  does not change.
- **Apify (train):** with `TRAIN_COST_ENABLED=1` each side with a HEL option
  costs one run per date, just as now with two separate searches. By default the
  train is off, so 0 runs.
- **php-fpm:** now two concurrent requests come in. The standard
  `pm.max_children` (5) is plenty for that.
