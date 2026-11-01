# Status: Outbound and return on one screen

- Gate 1 — Product: APPROVED 2026-10-02
- Gate 2 — Architecture: APPROVED 2026-10-02
- Gate 3 — Program Design: APPROVED 2026-10-02
- Gate 4 — Slice plan: APPROVED 2026-10-02

## Slices
- [x] Slice 1 — tracer bullet: two dates, two parallel calls, two bare columns, placeholder total
- [x] Slice 2 — happy path: roundTrip.ts + money.ts, cheapest outbound + return as real total
- [x] Slice 3 — choose yourself: clickable OptionCard, total calculated
- [x] Slice 4 — return date optional + date validation
- [x] Slice 5 — robust: error per side, no options, missing side in total bar (requestId removed)
- [x] Slice 6 — home country NL/FI + booked trips to the right side

## Notes for a fresh session
- Owner request (2026-10-02): "I'd like to choose 2 dates for outbound and return.
  I'd like to see it on 1 screen. I like to book separate trips so it doesn't
  need to be in 1 booking."
- Result: outbound and return are two separate one-way trips; no round-trip fares.
- `toevoeging.txt` (bus/train source HEL–TKU) is a **different** wish, not this feature.
- Slice 1 seen in the real app (2026-10-02): two calls (nl-fi 29-10, fi-nl 09-11),
  both 200, two columns. **Open for slice 2/3:** the right column overflows beyond
  the 76rem width — the min-widths of the `OptionCard` grid don't fit in half a column.
  The OptionCard layout must be more compact in a column.
- Slice 2 seen in the real app (2026-10-02): Finnair AY1302 € 139 + KLM KL1254 € 84
  = € 223.00 in the total bar. Mutations (defaultSelection → always 0; combine counts
  only outbound) turn 3 and 7 tests red respectively. Column overflow still open →
  slice 3.
- Test helpers are in `web/src/__tests__/fixtures.ts` (`makeOption`, `makeResult`, `makeLeg`).
- Slice 3 seen in the real app (2026-10-02): KLM KL1251 € 155 + SAS SK2727 € 169
  clicked → € 324.00, no extra /api/search calls. Column overflow resolved
  (measured: right column ends at x=1568, same as total bar; was 1656).
  Columns now stack below 70rem instead of 52rem.
- **Open copy point:** the total bar says "Cheapest combination" even after you choose
  something else. Submitted to the owner.
- Slice 4 seen in the real app (2026-10-02): return < outbound → message, 0 calls;
  empty return date → 1 call, 1 column, "Outbound only"; own choice → "Your choice".
  Copy point "Cheapest combination" resolved (owner: "take note").
  Column headers and the return column follow the dates from the **last search**
  (`searched`), not what's in the form since then.
- **Development environment pitfall:** Vite's file-watcher (bind-mount) missed one edit;
  the browser got an old `TotalBar`. Tests were green. `touch` on the file fixes it.
  In doubt: `curl localhost:8080/src/components/X.vue | grep`.
- No `:min` on the return date: Chrome blocks the form itself with its own tooltip
  and our message never shows; one validation, ours.
- Slice 5 seen in the real app (2026-10-02): fi-nl via a fetch stub set to 503,
  nl-fi real. Outbound column stays, return column shows the error, total bar € 139.00 +
  "Return journey missing — not counted". Button `disabled` while loading, then on again.
- Slice 6 seen in the real app (2026-10-02): start country Finland, scheduled trips
  "25 Oct → Netherlands" → Outbound (FI → NL), "29 Oct → Finland" → Return (NL → FI).
  Calls: 2026-10-25 fi-nl and 2026-10-29 nl-fi, both 200; total KLM € 123 +
  Finnair € 139 = € 262.00.
- **All six slices done.** Frontend 53/53 green, build green. Backend untouched.
