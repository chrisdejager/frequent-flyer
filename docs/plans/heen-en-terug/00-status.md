# Status: Heen en terug op één scherm

- Gate 1 — Product: APPROVED 2026-10-02
- Gate 2 — Architecture: APPROVED 2026-10-02
- Gate 3 — Program Design: APPROVED 2026-10-02
- Gate 4 — Slice plan: APPROVED 2026-10-02

## Slices
- [x] Slice 1 — tracer bullet: twee datums, twee parallelle calls, twee kale kolommen, placeholder-totaal
- [x] Slice 2 — happy path: roundTrip.ts + money.ts, goedkoopste heen + terug als echt totaal
- [ ] Slice 3 — zelf kiezen: klikbare OptionCard, totaal rekent mee
- [ ] Slice 4 — terugdatum optioneel + datumcontrole
- [ ] Slice 5 — robuust: fout per kant, geen opties, requestId tegen racecondities
- [ ] Slice 6 — startland NL/FI + geplande reizen naar de juiste kant

## Notes for a fresh session
- Verzoek eigenaar (2026-10-02): "Ik zou graag 2 datums kunnen kiezen voor heen
  en terug reis. Ik zou het graag op 1 scherm zien. Ik boek graag losse reizen
  dus het hoeft niet in 1 boeking."
- Gevolg: heen en terug zijn twee losse enkele reizen; geen retourtarieven zoeken.
- `toevoeging.txt` (bus/trein-bron HEL–TKU) is een **andere** wens, niet deze feature.
- Slice 1 gezien in de echte app (2026-10-02): twee calls (nl-fi 29-10, fi-nl 09-11),
  beide 200, twee kolommen. **Open voor slice 2/3:** de rechterkolom loopt buiten
  de 76rem-breedte — de min-breedtes van de `OptionCard`-grid passen niet in een
  halve kolom. De OptionCard-layout moet compacter in een kolom.
- Slice 2 gezien in de echte app (2026-10-02): Finnair AY1302 € 139 + KLM KL1254 € 84
  = € 223,00 in de totaalbalk. Mutaties (defaultSelection → altijd 0; combine telt
  alleen heen) maken resp. 3 en 7 tests rood. Kolom-overloop staat nog open → slice 3.
- Testhulpen staan in `web/src/__tests__/fixtures.ts` (`makeOption`, `makeResult`, `makeLeg`).
