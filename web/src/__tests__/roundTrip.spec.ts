import { describe, expect, it } from 'vitest'
import { chosenOption, combine, defaultSelection, legDirections, shiftedDate, validateDates } from '../roundTrip'
import { euro } from '../money'
import { makeLeg, makeOption, makeResult } from './fixtures'

describe('roundTrip', () => {
  it('legDirections gives nl-fi outbound and fi-nl return when starting in Netherlands', () => {
    expect(legDirections('nl')).toEqual({ out: 'nl-fi', back: 'fi-nl' })
  })

  it('legDirections reverses when starting in Finland', () => {
    expect(legDirections('fi')).toEqual({ out: 'fi-nl', back: 'nl-fi' })
  })

  it('defaultSelection chooses the first option: the API sends them cheapest first', () => {
    expect(defaultSelection(makeResult([makeOption('KLM', 9000), makeOption('Finnair', 12000)]))).toBe(0)
  })

  it('defaultSelection returns null without options', () => {
    expect(defaultSelection(makeResult([]))).toBeNull()
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
