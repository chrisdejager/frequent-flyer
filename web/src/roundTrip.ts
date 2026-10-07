import type { Direction, LegState, Option, SearchResult, StartCountry, Trip } from './types'

export function legDirections(start: StartCountry): { out: Direction; back: Direction } {
  return start === 'nl'
    ? { out: 'nl-fi', back: 'fi-nl' }
    : { out: 'fi-nl', back: 'nl-fi' }
}

/** First ranked option (trainCostKnown); none → null. */
export function defaultSelection(result: SearchResult): number | null {
  const index = result.options.findIndex(o => o.cost.trainCostKnown)

  return index === -1 ? null : index
}

export interface IndexedOption {
  option: Option
  index: number
}

/** Ranked/unranked preserving original index. */
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
  /** A chosen option has trainCostIncluded=false. */
  excludesTransfer: boolean
  /** A chosen option has trainCostKnown=false. */
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

/** null = valid; otherwise a message. Empty return date = one-way, so valid. */
export function validateDates(outDate: string, backDate: string): string | null {
  // 'YYYY-MM-DD' sorts as text equal to the date itself.
  if (backDate !== '' && backDate < outDate) {
    return 'Return date is before departure date.'
  }

  return null
}

/** Which leg does this planned trip belong to, given the starting country? */
export function legForTrip(trip: Trip, start: StartCountry): 'out' | 'back' {
  return trip.direction === legDirections(start).out ? 'out' : 'back'
}
