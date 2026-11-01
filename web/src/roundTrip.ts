import type { Direction, LegState, Option, SearchResult, StartCountry } from './types'

export function legDirections(start: StartCountry): { out: Direction; back: Direction } {
  return start === 'nl'
    ? { out: 'nl-fi', back: 'fi-nl' }
    : { out: 'fi-nl', back: 'nl-fi' }
}

/** The cheapest option: the API sends them cheapest first. None → null. */
export function defaultSelection(result: SearchResult): number | null {
  return result.options.length > 0 ? 0 : null
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
}

export function combine(out: Option | null, back: Option | null): Combined {
  const chosen = [out, back].filter((o): o is Option => o !== null)

  return {
    totalCents: chosen.reduce((sum, o) => sum + o.cost.total, 0),
    legsCounted: chosen.length,
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

/**
 * The searched date of one leg moved by `days`; null when that is not allowed:
 * before today, outbound after return, or a return that was not searched.
 * 'YYYY-MM-DD' sorts as text equal to the date itself.
 */
export function shiftedDate(
  searched: { out: string; back: string },
  leg: 'out' | 'back',
  days: number,
  today: string,
): string | null {
  const from = searched[leg]

  if (from === '') {
    return null
  }

  const date = new Date(`${from}T00:00:00Z`)
  date.setUTCDate(date.getUTCDate() + days)
  const shifted = date.toISOString().slice(0, 10)

  const out = leg === 'out' ? shifted : searched.out
  const back = leg === 'back' ? shifted : searched.back

  if (shifted < today || validateDates(out, back) !== null) {
    return null
  }

  return shifted
}
