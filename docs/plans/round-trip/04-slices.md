# Slices: Outbound and return on one screen

Each slice ends green (`npm run test` + `npm run build`). I prove each slice
in the running app at http://localhost:8080 and deliver it as one commit.

1. **Tracer bullet.**
   - The form gets two dates: outbound (NL→FI) and return (FI→NL).
   - "Search" starts two parallel `search()` calls.
   - Two bare `LegColumn` columns appear side by side, stacking one below the
     other on a narrow screen.
   - `TotalBar` exists already, but still shows a fixed placeholder ("€ —").
   - Tests: `searches outbound and return with the correct direction and date per side`.
2. **Happy path with the real total.**
   - `roundTrip.ts`: `legDirections`, `defaultSelection`, `partition`,
     `chosenOption` and `combine`.
   - `money.ts` gets `euro`; `OptionCard` uses that from now on.
   - Each column splits into ranked and unranked, with the divider.
   - The total bar shows cheapest outbound + cheapest return, with the
     notes "excl. transfer", "incomplete" and "indicative".
3. **Choose yourself.**
   - `OptionCard` gets `selected` and `select`.
   - A click changes the choice on that side and the total is recalculated, without
     a new search.
4. **Return date optional and date validation.**
   - Without return date: one call, one column and the message "outbound only".
   - `validateDates`: with a return date before the outbound date a message follows and
     no call.
5. **Robust.**
   - An error on one side leaves the other side standing.
   - "No options" per side.
   - The total bar notes when the return trip is missing.
   - ~~`requestId`~~ removed (owner, 2026-10-02): the Search button is disabled while
     loading, so the race is unreachable via the UI. A
     test fixes that disabled button.
6. **Start country and scheduled trips.**
   - A choice "Departing from Netherlands/Finland" flips both directions.
   - `legForTrip`: a scheduled trip fills the date on the correct side.
   - The labels in the form follow the start country.
