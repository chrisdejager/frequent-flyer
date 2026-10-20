import { describe, expect, it } from 'vitest'
import { chosenOption, combine, defaultSelection, legDirections, legForTrip, partition, shiftedDate, validateDates } from '../roundTrip'
import { euro } from '../money'
import { makeLeg, makeOption, makeResult } from './fixtures'

describe('roundTrip', () => {
  it('legDirections gives nl-fi outbound and fi-nl return when starting in Netherlands', () => {
    expect(legDirections('nl')).toEqual({ out: 'nl-fi', back: 'fi-nl' })
  })

  it('legDirections reverses when starting in Finland', () => {
    expect(legDirections('fi')).toEqual({ out: 'fi-nl', back: 'nl-fi' })
  })

  it('defaultSelection chooses the first ranked option, even if an unranked one comes before', () => {
    const result = makeResult([
      makeOption('KLM', 9000, { trainCostKnown: false }),
      makeOption('Finnair', 12000),
    ])

    expect(defaultSelection(result)).toBe(1)
  })

  it('defaultSelection returns null if there are only unranked options', () => {
    expect(defaultSelection(makeResult([makeOption('KLM', 9000, { trainCostKnown: false })]))).toBeNull()
    expect(defaultSelection(makeResult([]))).toBeNull()
  })

  it('partition preserves the original index', () => {
    const a = makeOption('KLM', 9000, { trainCostKnown: false })
    const b = makeOption('Finnair', 12000)
    const c = makeOption('SAS', 15000)

    expect(partition([a, b, c])).toEqual({
      ranked: [{ option: b, index: 1 }, { option: c, index: 2 }],
      unranked: [{ option: a, index: 0 }],
    })
  })

  it('chosenOption returns the chosen option, or null without choice or result', () => {
    const b = makeOption('Finnair', 12000)
    const result = makeResult([makeOption('KLM', 9000), b])

    expect(chosenOption(makeLeg(result, 1))).toBe(b)
    expect(chosenOption(makeLeg(result, null))).toBeNull()
    expect(chosenOption(makeLeg(null, 0))).toBeNull()
  })

  it('combine adds up both totals in cents', () => {
    // Amounts with loose cents: a rounding or factor-100 error shows up.
    const combined = combine(makeOption('KLM', 15650), makeOption('Finnair', 9875))

    expect(combined.totalCents).toBe(25525)
    expect(combined.legsCounted).toBe(2)
    expect(euro(combined.totalCents)).toBe('€ 255,25')
  })

  it('combine with one leg counts only that leg and reports legsCounted 1', () => {
    expect(combine(makeOption('KLM', 15650), null)).toMatchObject({ totalCents: 15650, legsCounted: 1 })
    expect(combine(null, makeOption('KLM', 9875))).toMatchObject({ totalCents: 9875, legsCounted: 1 })
    expect(combine(null, null)).toMatchObject({ totalCents: 0, legsCounted: 0 })
  })

  it('combine sets excludesTransfer if a chosen option does not count the transfer', () => {
    const plain = makeOption('SAS', 10000)
    const excl = makeOption('KLM', 10000, { trainCostIncluded: false })

    expect(combine(plain, plain).excludesTransfer).toBe(false)
    expect(combine(plain, excl).excludesTransfer).toBe(true)
    expect(combine(excl, null).excludesTransfer).toBe(true)
  })

  it('combine sets incomplete if a chosen option has an unknown train cost', () => {
    const plain = makeOption('SAS', 10000)

    expect(combine(plain, plain).incomplete).toBe(false)
    expect(combine(makeOption('KLM', 1, { trainCostKnown: false }), plain).incomplete).toBe(true)
  })

  it('combine sets indicative if a chosen option is indicative', () => {
    const plain = makeOption('SAS', 10000)

    expect(combine(plain, plain).indicative).toBe(false)
    expect(combine(plain, makeOption('KLM', 1, { indicative: true })).indicative).toBe(true)
  })
})

describe('validateDates', () => {
  it('accepts an empty return date', () => {
    expect(validateDates('2026-10-29', '')).toBeNull()
  })

  it('accepts the same day for outbound and return', () => {
    expect(validateDates('2026-10-29', '2026-10-29')).toBeNull()
  })

  it('rejects a return date before the departure date', () => {
    expect(validateDates('2026-10-29', '2026-10-28')).toBe('Return date is before departure date.')
  })
})

describe('legForTrip', () => {
  const trip = (direction: 'nl-fi' | 'fi-nl') => ({ date: '2026-10-29', direction, traveller: 'chris' as const })

  it('links nl-fi to outbound when starting NL and to return when starting FI', () => {
    expect(legForTrip(trip('nl-fi'), 'nl')).toBe('out')
    expect(legForTrip(trip('nl-fi'), 'fi')).toBe('back')
  })

  it('links fi-nl to return when starting NL and to outbound when starting FI', () => {
    expect(legForTrip(trip('fi-nl'), 'nl')).toBe('back')
    expect(legForTrip(trip('fi-nl'), 'fi')).toBe('out')
  })
})

describe('shiftedDate', () => {
  const today = '2026-10-20'

  it('moves a leg one day forward or back', () => {
    expect(shiftedDate({ out: '2026-10-29', back: '2026-11-09' }, 'out', 1, today)).toBe('2026-10-30')
    expect(shiftedDate({ out: '2026-10-29', back: '2026-11-09' }, 'back', -1, today)).toBe('2026-11-08')
  })

  it('crosses month and year ends', () => {
    expect(shiftedDate({ out: '2026-10-31', back: '' }, 'out', 1, today)).toBe('2026-11-01')
    expect(shiftedDate({ out: '2026-12-31', back: '' }, 'out', 1, today)).toBe('2027-01-01')
  })

  it('never goes before today', () => {
    expect(shiftedDate({ out: '2026-10-20', back: '' }, 'out', -1, today)).toBeNull()
    expect(shiftedDate({ out: '2026-10-21', back: '' }, 'out', -1, today)).toBe('2026-10-20')
  })

  it('never puts the outbound after the return', () => {
    expect(shiftedDate({ out: '2026-11-09', back: '2026-11-09' }, 'out', 1, today)).toBeNull()
    expect(shiftedDate({ out: '2026-11-09', back: '2026-11-09' }, 'back', -1, today)).toBeNull()
    // Same day out and back is allowed, as in the form.
    expect(shiftedDate({ out: '2026-11-08', back: '2026-11-09' }, 'out', 1, today)).toBe('2026-11-09')
  })

  it('a return that was not searched cannot be shifted', () => {
    expect(shiftedDate({ out: '2026-10-29', back: '' }, 'back', 1, today)).toBeNull()
  })
})
