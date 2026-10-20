import type { Cost, LegState, Option, SearchResult } from '../types'

export function makeOption(carrierLabel: string, total: number, cost: Partial<Cost> = {}): Option {
  return {
    carrier: carrierLabel.slice(0, 2).toUpperCase(),
    carrierLabel,
    pattern: 'through',
    origin: 'AMS',
    destination: 'TKU',
    departAt: '2026-10-29T08:00:00+00:00',
    arriveAt: '2026-10-29T14:00:00+00:00',
    stops: 1,
    via: 'ARN',
    connectionMinutes: 60,
    flightNumbers: ['XX1'],
    bookingRef: '0ea5aa41342f00000056346ef96d746d',
    observedAt: null,
    cost: {
      basePrice: total,
      trainCost: 0,
      total,
      totalFormatted: `€ ${(total / 100).toFixed(2).replace('.', ',')}`,
      indicative: false,
      trainCostKnown: true,
      trainCostIncluded: true,
      ...cost,
    },
  }
}

export function makeResult(options: Option[]): SearchResult {
  return { traveller: { id: 'partner', name: 'Partner' }, searchedAt: '2026-10-02T10:00:00Z', options }
}

export function makeLeg(result: SearchResult | null, selected: number | null): LegState {
  return { direction: 'nl-fi', date: '2026-10-29', result, error: '', loading: false, selected }
}
