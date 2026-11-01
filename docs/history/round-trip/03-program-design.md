# Program Design: Outbound and return on one screen

Only the frontend changes (Gate 2). The backend and `api.ts` remain unchanged.

## Files
- `web/src/roundTrip.ts` (**new**): pure functions without Vue. They determine the
  directions, validate dates, choose the default option, add up the total
  and divide options into ranked and unranked. Because there is no Vue in
  it, they are fast and easy to test.
- `web/src/money.ts` (**new**): the function `euro(cents)`, moved from
  `OptionCard.vue`. The total bar uses the same notation, so formatting
  belongs in one place.
- `web/src/types.ts`: gets the new types `StartCountry` and `LegState`.
- `web/src/components/OptionCard.vue`: becomes selectable with a prop `selected` and
  an event `select`. It now uses `euro` from `money.ts`; the display
  stays otherwise the same.
- `web/src/components/LegColumn.vue` (**new**): one column for one side, with
  header, loading status, error, "no options", the ranked options, the divider
  and the unranked options. This takes over the splitting into ranked and
  unranked that now sits in `App.vue`.
- `web/src/components/TotalBar.vue` (**new**): shows the chosen option per side,
  the sum and the notes (outbound only / excludes transfer / incomplete).
- `web/src/App.vue`: the form gets a start country and two dates. Per side
  comes a `LegState`, the searches run in parallel, the scheduled trips are
  linked to the correct side and the whole consists of two columns with a
  total bar.
- Tests: `web/src/__tests__/roundTrip.spec.ts` (new),
  `components/__tests__/LegColumn.spec.ts` (new), `TotalBar.spec.ts` (new),
  `App.spec.ts` (adapted) and `OptionCard.spec.ts` (expanded).

## Types & signatures

```ts
// types.ts
export type StartCountry = 'nl' | 'fi'

export interface LegState {
  direction: Direction
  date: string                 // 'YYYY-MM-DD'; '' = this side is not searched
  result: SearchResult | null
  error: string
  loading: boolean
  /** Index in result.options; null = nothing chosen. */
  selected: number | null
}
```

```ts
// roundTrip.ts
export function legDirections(start: StartCountry): { out: Direction; back: Direction }

/** null = valid; otherwise an error message in English. Blank return date is valid. */
export function validateDates(outDate: string, backDate: string): string | null

/** First ranked option (trainCostKnown); none → null. */
export function defaultSelection(result: SearchResult): number | null

/** Ranked/unranked with original index retained. */
export function partition(options: Option[]): {
  ranked: Array<{ option: Option; index: number }>
  unranked: Array<{ option: Option; index: number }>
}

export function chosenOption(leg: LegState): Option | null

export interface Combined {
  totalCents: number
  legsCounted: number          // 0, 1 or 2
  excludesTransfer: boolean    // a chosen option has trainCostIncluded=false
  incomplete: boolean          // a chosen option has trainCostKnown=false
  indicative: boolean
}
export function combine(out: Option | null, back: Option | null): Combined

/** Which side does this scheduled trip belong to, given the start country? */
export function legForTrip(trip: Trip, start: StartCountry): 'out' | 'back'
```

```ts
// money.ts
export function euro(cents: number): string   // 15650 → '€ 156.50'
```

```ts
// OptionCard.vue
defineProps<{ option: Option; selected?: boolean }>()
defineEmits<{ select: [] }>()

// LegColumn.vue
defineProps<{ title: string; leg: LegState }>()
defineEmits<{ select: [index: number] }>()

// TotalBar.vue
defineProps<{ out: Option | null; back: Option | null; backRequested: boolean; isDefault: boolean }>()
// isDefault added in slice 4 (owner decision 2026-10-02): "Cheapest combination"
// vs. "Your choice" once a side differs from defaultSelection.
```

## Call stack

**Search**
```
App.run()
  validateDates(outDate, backDate)  → on error: show error, return (no call)
  legDirections(start)              → out.direction, back.direction
  Promise.allSettled([
    search(out.date, out.direction, traveller),
    back.date ? search(back.date, back.direction, traveller) : skip,
  ])
  per side: fulfilled → result + selected = defaultSelection(result)
            rejected  → error = message, result = null
```

**Display**
```
App.vue
  TotalBar(out = chosenOption(outLeg), back = chosenOption(backLeg), backRequested)
    combine(out, back) → sum + notes, euro(totalCents)
  LegColumn(title="Outbound — …", leg=outLeg)  @select → outLeg.selected = i
    partition(leg.result.options)
    OptionCard(option, selected = index === leg.selected) @select → emit('select', index)
  LegColumn(title="Return — …", leg=backLeg) (only if backLeg.date)
```

**Scheduled trip**
```
App.pick(trip)
  legForTrip(trip, start) === 'out' ? outDate = trip.date : backDate = trip.date
  traveller = trip.traveller
```

## Test plan

`roundTrip.spec.ts`
- `legDirections gives nl-fi outbound and fi-nl return when starting in Netherlands`
- `legDirections flips when starting in Finland`
- `validateDates accepts a blank return date`
- `validateDates accepts the same day outbound and return`
- `validateDates rejects a return date before the outbound date`
- `defaultSelection picks the first ranked option, even if an unranked one comes first`
- `defaultSelection returns null if there are only unranked options`
- `partition retains the original index`
- `combine adds both totals in cents`: two amounts with cents, so an
  error with rounding or a factor of 100 is visible
- `combine with one side counts only that side and reports legsCounted 1`
- `combine sets excludesTransfer if one chosen option does not count the transfer`
- `combine sets incomplete if one chosen option has an unknown train price`
- `legForTrip links nl-fi to outbound when starting NL and to return when starting FI`

`OptionCard.spec.ts` (existing tests stay)
- `click on the card emits select`
- `selected marks the card (aria-pressed)`

`LegColumn.spec.ts`
- `shows ranked options before the divider and unranked after it`:
  this test moves from `App.spec.ts`, with the same position check
- `emit the original index if an unranked option is clicked`
- `shows the error message of this side only`
- `shows "no options" with an empty result`

`TotalBar.spec.ts`
- `shows the sum of outbound and return as euro`
- `reports "outbound only" if there is no return date`
- `reports that the return trip is missing if there was a return date but no choice`
- `shows "excludes transfer HEL→TKU" if a chosen option requests it`

`App.spec.ts` (`api` stays mocked, so no network)
- `searches outbound and return with the correct direction and date per side`: checks
  both `search` calls with their arguments
- `searches only once without a return date`
- `starting in Finland flips both directions`
- `does not search with a return date before the outbound date and shows a message`
- `an error on one side leaves the other side standing`
- `by default the total is cheapest outbound + cheapest return`
- `clicking a different option changes the total without a new search`
- `a late response from an older search does not overwrite the new one`
- `a scheduled return trip fills the return date`
- `shows the name of the traveller after a search` (exists already)

Prove with a mutation, per the method in `AGENTS.md`. With each fix or rule
I run the test once against the code without that rule and show that it
turns red.

## Least confident decisions
1. **Unranked options (unknown train price) are clickable.** The
   total then gets "incomplete". The alternative is to make them not clickable.
   With `TRAIN_COST_ENABLED=0` they do not occur in practice.
2. **The choice is stored as an index in `result.options`**, not as
   flight number. That works because a result doesn't change after search;
   with a new search the choice is reset.
3. **`requestId` against race conditions — REMOVED in slice 5** (owner,
   2026-10-02). The Search button is `disabled` while loading, and a disabled
   standard button also blocks Enter-submit. So the race is unreachable; the
   test `search button is disabled as long as one side is still loading`
   guards that. **If there ever becomes a way to search while loading
   (e.g. a scheduled trip that searches directly), this must come back.**
4. **The return column appears only if there is a return date.** Without a return date
   the screen looks as it does now, with one column.
5. **The date error sits above the form** as a general `error`, separate from
   the per-side errors.
6. **`euro()` moves to `money.ts`.** That is a small refactor of
   `OptionCard` within this feature. The alternative is to format the amount twice.
