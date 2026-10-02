# Slices: Heen en terug op één scherm

Elke slice eindigt groen (`npm run test` + `npm run build`). Ik bewijs elke slice
in de draaiende app op http://localhost:8080 en lever hem af als één commit.

1. **Tracer bullet.**
   - Het formulier krijgt twee datums: heen (NL→FI) en terug (FI→NL).
   - "Zoek" start twee parallelle `search()`-calls.
   - Er komen twee kale `LegColumn`-kolommen naast elkaar, die onder elkaar
     schuiven op een smal scherm.
   - `TotalBar` bestaat al, maar toont nog een vaste placeholder ("€ —").
   - Tests: `zoekt heen en terug met de juiste richting en datum per kant`.
2. **Happy path met het echte totaal.**
   - `roundTrip.ts`: `legDirections`, `defaultSelection`, `partition`,
     `chosenOption` en `combine`.
   - `money.ts` krijgt `euro`; `OptionCard` gebruikt die voortaan.
   - Elke kolom splitst in gerangschikt en ongerangschikt, met de scheidingslijn.
   - De totaalbalk toont goedkoopste heen + goedkoopste terug, met de
     opmerkingen "excl. transfer", "onvolledig" en "indicatief".
3. **Zelf kiezen.**
   - `OptionCard` krijgt `selected` en `select`.
   - Een klik verandert de keuze aan die kant en het totaal rekent mee, zonder
     nieuwe zoekactie.
4. **Terugdatum optioneel en datumcontrole.**
   - Zonder terugdatum: één call, één kolom en de melding "alleen heenreis".
   - `validateDates`: bij een terugdatum vóór de heendatum volgt een melding en
     geen call.
5. **Robuust.**
   - Een fout aan één kant laat de andere kant staan.
   - "Geen opties" per kant.
   - De totaalbalk meldt als de terugreis ontbreekt.
   - `requestId` voorkomt dat een laat antwoord van een oudere zoekactie de
     nieuwe overschrijft.
6. **Startland en geplande reizen.**
   - Een keuze "Vertrek vanuit Nederland/Finland" draait beide richtingen om.
   - `legForTrip`: een geplande reis vult de datum bij de juiste kant.
   - De labels in het formulier volgen het startland.
