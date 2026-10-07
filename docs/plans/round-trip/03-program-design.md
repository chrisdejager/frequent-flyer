# Program Design: Heen en terug op één scherm

Alleen de frontend verandert (Gate 2). De backend en `api.ts` blijven ongewijzigd.

## Files
- `web/src/roundTrip.ts` (**nieuw**): pure functies zonder Vue. Ze bepalen de
  richtingen, controleren de datums, kiezen de standaardoptie, tellen het totaal
  op en verdelen opties in gerangschikt en ongerangschikt. Omdat er geen Vue in
  zit, zijn ze snel en los te testen.
- `web/src/money.ts` (**nieuw**): de functie `euro(cents)`, verhuisd uit
  `OptionCard.vue`. De totaalbalk gebruikt dezelfde notatie, dus het formatteren
  hoort op één plek.
- `web/src/types.ts`: krijgt de nieuwe types `StartCountry` en `LegState`.
- `web/src/components/OptionCard.vue`: wordt kiesbaar met een prop `selected` en
  een event `select`. Hij gebruikt voortaan `euro` uit `money.ts`; de weergave
  blijft verder gelijk.
- `web/src/components/LegColumn.vue` (**nieuw**): één kolom voor één kant, met
  kop, laadstatus, fout, "geen opties", de gerangschikte opties, de scheidingslijn
  en de ongerangschikte opties. Dit neemt het splitsen in gerangschikt en
  ongerangschikt over dat nu in `App.vue` zit.
- `web/src/components/TotalBar.vue` (**nieuw**): toont de gekozen optie per kant,
  de som en de opmerkingen (alleen heen / exclusief transfer / onvolledig).
- `web/src/App.vue`: het formulier krijgt een startland en twee datums. Per kant
  komt een `LegState`, de zoekacties lopen parallel, de geplande reizen worden
  aan de juiste kant gekoppeld en het geheel bestaat uit twee kolommen met een
  totaalbalk.
- Tests: `web/src/__tests__/roundTrip.spec.ts` (nieuw),
  `components/__tests__/LegColumn.spec.ts` (nieuw), `TotalBar.spec.ts` (nieuw),
  `App.spec.ts` (aangepast) en `OptionCard.spec.ts` (uitgebreid).

## Types & signatures

```ts
// types.ts
export type StartCountry = 'nl' | 'fi'

export interface LegState {
  direction: Direction
  date: string                 // 'YYYY-MM-DD'; '' = deze kant wordt niet gezocht
  result: SearchResult | null
  error: string
  loading: boolean
  /** Index in result.options; null = niets gekozen. */
  selected: number | null
}
```

```ts
// roundTrip.ts
export function legDirections(start: StartCountry): { out: Direction; back: Direction }

/** null = geldig; anders een Nederlandse foutmelding. Lege terugdatum is geldig. */
export function validateDates(outDate: string, backDate: string): string | null

/** Eerste gerangschikte optie (trainCostKnown); geen → null. */
export function defaultSelection(result: SearchResult): number | null

/** Gerangschikt/ongerangschikt met behoud van de oorspronkelijke index. */
export function partition(options: Option[]): {
  ranked: Array<{ option: Option; index: number }>
  unranked: Array<{ option: Option; index: number }>
}

export function chosenOption(leg: LegState): Option | null

export interface Combined {
  totalCents: number
  legsCounted: number          // 0, 1 of 2
  excludesTransfer: boolean    // een gekozen optie heeft trainCostIncluded=false
  incomplete: boolean          // een gekozen optie heeft trainCostKnown=false
  indicative: boolean
}
export function combine(out: Option | null, back: Option | null): Combined

/** Bij welke kant hoort deze geplande reis, gegeven het startland? */
export function legForTrip(trip: Trip, start: StartCountry): 'out' | 'back'
```

```ts
// money.ts
export function euro(cents: number): string   // 15650 → '€ 156,50'
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
// isDefault toegevoegd in slice 4 (eigenaarsbesluit 2026-10-02): "Goedkoopste combinatie"
// vs. "Jouw keuze" zodra een kant afwijkt van defaultSelection.
```

## Call stack

**Zoeken**
```
App.run()
  validateDates(outDate, backDate)  → bij een fout: error tonen, return (geen call)
  legDirections(start)              → out.direction, back.direction
  Promise.allSettled([
    search(out.date, out.direction, traveller),
    back.date ? search(back.date, back.direction, traveller) : skip,
  ])
  per kant: fulfilled → result + selected = defaultSelection(result)
            rejected  → error = message, result = null
```

**Weergave**
```
App.vue
  TotalBar(out = chosenOption(outLeg), back = chosenOption(backLeg), backRequested)
    combine(out, back) → som + opmerkingen, euro(totalCents)
  LegColumn(title="Heen — …", leg=outLeg)  @select → outLeg.selected = i
    partition(leg.result.options)
    OptionCard(option, selected = index === leg.selected) @select → emit('select', index)
  LegColumn(title="Terug — …", leg=backLeg) (alleen als backLeg.date)
```

**Geplande reis**
```
App.pick(trip)
  legForTrip(trip, start) === 'out' ? outDate = trip.date : backDate = trip.date
  traveller = trip.traveller
```

## Test plan

`roundTrip.spec.ts`
- `legDirections geeft nl-fi heen en fi-nl terug bij start in Nederland`
- `legDirections draait om bij start in Finland`
- `validateDates accepteert een lege terugdatum`
- `validateDates accepteert dezelfde dag heen en terug`
- `validateDates weigert een terugdatum vóór de heendatum`
- `defaultSelection kiest de eerste gerangschikte optie, ook als er een ongerangschikte vóór staat`
- `defaultSelection geeft null als er alleen ongerangschikte opties zijn`
- `partition behoudt de oorspronkelijke index`
- `combine telt beide totalen in centen op`: twee bedragen met centen, zodat een
  fout met afronden of met factor 100 zichtbaar wordt
- `combine met één kant telt alleen die kant en meldt legsCounted 1`
- `combine zet excludesTransfer als één gekozen optie de transfer niet meetelt`
- `combine zet incomplete als één gekozen optie een onbekende treinprijs heeft`
- `legForTrip koppelt nl-fi aan heen bij start NL en aan terug bij start FI`

`OptionCard.spec.ts` (bestaande tests blijven)
- `klik op de kaart emit select`
- `selected markeert de kaart (aria-pressed)`

`LegColumn.spec.ts`
- `toont gerangschikte opties vóór de scheidingslijn en ongerangschikte erna`:
  deze test verhuist uit `App.spec.ts`, met dezelfde positietoets
- `emit de oorspronkelijke index als een ongerangschikte optie wordt aangeklikt`
- `toont de foutmelding van alleen deze kant`
- `toont "geen opties" bij een leeg resultaat`

`TotalBar.spec.ts`
- `toont de som van heen en terug als euro`
- `meldt "alleen heenreis" als er geen terugdatum is`
- `meldt dat de terugreis ontbreekt als er een terugdatum was maar geen keuze`
- `toont "exclusief transfer HEL→TKU" als een gekozen optie dat vraagt`

`App.spec.ts` (`api` blijft gemockt, dus geen netwerk)
- `zoekt heen en terug met de juiste richting en datum per kant`: controleert
  beide `search`-aanroepen met hun argumenten
- `zoekt maar één keer zonder terugdatum`
- `start in Finland draait beide richtingen om`
- `zoekt niet bij een terugdatum vóór de heendatum en toont een melding`
- `een fout aan één kant laat de andere kant staan`
- `standaard is het totaal goedkoopste heen + goedkoopste terug`
- `een andere optie aanklikken past het totaal aan zonder nieuwe zoekactie`
- `een laat antwoord van een oudere zoekactie overschrijft de nieuwe niet`
- `een geplande terugreis vult de terugdatum`
- `toont de naam van de reiziger na een zoekopdracht` (bestaat al)

Bewijs met een mutatie, per de werkwijze in `AGENTS.md`. Bij elke fix of regel
draai ik de test één keer tegen de code zonder die regel en laat ik zien dat hij
rood wordt.

## Least confident decisions
1. **Ongerangschikte opties (onbekende treinprijs) zijn aan te klikken.** Het
   totaal krijgt dan "onvolledig". Het alternatief is ze niet kiesbaar maken.
   Met `TRAIN_COST_ENABLED=0` komen ze in de praktijk niet voor.
2. **De keuze wordt bewaard als index in `result.options`**, niet als
   vluchtnummer. Dat werkt omdat een resultaat na het zoeken niet meer
   verandert; bij een nieuwe zoekactie wordt de keuze opnieuw gezet.
3. **`requestId` tegen racecondities — GESCHRAPT in slice 5** (eigenaar,
   2026-10-02). De Zoek-knop staat tijdens het laden op `disabled`, en een
   uitgeschakelde standaardknop blokkeert ook Enter-submit. De race is dus niet
   te bereiken; de test `de zoekknop is uitgeschakeld zolang een kant nog laadt`
   bewaakt dat. **Komt er ooit een manier om tijdens het laden te zoeken
   (bv. een geplande reis die direct zoekt), dan moet dit terug.**
4. **De terugkolom verschijnt pas als er een terugdatum is.** Zonder terugdatum
   ziet het scherm eruit als nu, met één kolom.
5. **De datumfout staat boven het formulier** als algemene `error`, los van de
   fouten per kant.
6. **`euro()` verhuist naar `money.ts`.** Dat is een kleine refactor van
   `OptionCard` binnen deze feature. Het alternatief is het bedrag twee keer
   formatteren.
