import type { Direction, LegState, Option, SearchResult, StartCountry } from './types'

export function legDirections(start: StartCountry): { out: Direction; back: Direction } {
  return start === 'nl'
    ? { out: 'nl-fi', back: 'fi-nl' }
    : { out: 'fi-nl', back: 'nl-fi' }
}

/** Eerste gerangschikte optie (trainCostKnown); geen → null. */
export function defaultSelection(result: SearchResult): number | null {
  const index = result.options.findIndex(o => o.cost.trainCostKnown)

  return index === -1 ? null : index
}

export interface IndexedOption {
  option: Option
  index: number
}

/** Gerangschikt/ongerangschikt met behoud van de oorspronkelijke index. */
export function partition(options: Option[]): { ranked: IndexedOption[]; unranked: IndexedOption[] } {
  const indexed = options.map((option, index) => ({ option, index }))

  return {
    ranked: indexed.filter(({ option }) => option.cost.trainCostKnown),
    unranked: indexed.filter(({ option }) => !option.cost.trainCostKnown),
  }
}

export function chosenOption(leg: LegState): Option | null {
  if (leg.result === null || leg.selected === null) {
    return null
  }

  return leg.result.options[leg.selected] ?? null
}

export interface Combined {
  totalCents: number
  legsCounted: number
  /** Een gekozen optie heeft trainCostIncluded=false. */
  excludesTransfer: boolean
  /** Een gekozen optie heeft trainCostKnown=false. */
  incomplete: boolean
  indicative: boolean
}

export function combine(out: Option | null, back: Option | null): Combined {
  const chosen = [out, back].filter((o): o is Option => o !== null)

  return {
    totalCents: chosen.reduce((sum, o) => sum + o.cost.total, 0),
    legsCounted: chosen.length,
    excludesTransfer: chosen.some(o => !o.cost.trainCostIncluded),
    incomplete: chosen.some(o => !o.cost.trainCostKnown),
    indicative: chosen.some(o => o.cost.indicative),
  }
}

/** null = geldig; anders een melding. Lege terugdatum = enkele reis, dus geldig. */
export function validateDates(outDate: string, backDate: string): string | null {
  // 'YYYY-MM-DD' sorteert als tekst gelijk aan de datum zelf.
  if (backDate !== '' && backDate < outDate) {
    return 'Terugdatum ligt vóór de heendatum.'
  }

  return null
}
