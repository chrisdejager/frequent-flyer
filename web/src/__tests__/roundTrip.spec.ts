import { describe, expect, it } from 'vitest'
import { chosenOption, combine, defaultSelection, legDirections, legForTrip, partition, validateDates } from '../roundTrip'
import { euro } from '../money'
import { makeLeg, makeOption, makeResult } from './fixtures'

describe('roundTrip', () => {
  it('legDirections geeft nl-fi heen en fi-nl terug bij start in Nederland', () => {
    expect(legDirections('nl')).toEqual({ out: 'nl-fi', back: 'fi-nl' })
  })

  it('legDirections draait om bij start in Finland', () => {
    expect(legDirections('fi')).toEqual({ out: 'fi-nl', back: 'nl-fi' })
  })

  it('defaultSelection kiest de eerste gerangschikte optie, ook als er een ongerangschikte vóór staat', () => {
    const result = makeResult([
      makeOption('KLM', 9000, { trainCostKnown: false }),
      makeOption('Finnair', 12000),
    ])

    expect(defaultSelection(result)).toBe(1)
  })

  it('defaultSelection geeft null als er alleen ongerangschikte opties zijn', () => {
    expect(defaultSelection(makeResult([makeOption('KLM', 9000, { trainCostKnown: false })]))).toBeNull()
    expect(defaultSelection(makeResult([]))).toBeNull()
  })

  it('partition behoudt de oorspronkelijke index', () => {
    const a = makeOption('KLM', 9000, { trainCostKnown: false })
    const b = makeOption('Finnair', 12000)
    const c = makeOption('SAS', 15000)

    expect(partition([a, b, c])).toEqual({
      ranked: [{ option: b, index: 1 }, { option: c, index: 2 }],
      unranked: [{ option: a, index: 0 }],
    })
  })

  it('chosenOption geeft de gekozen optie, of null zonder keuze of resultaat', () => {
    const b = makeOption('Finnair', 12000)
    const result = makeResult([makeOption('KLM', 9000), b])

    expect(chosenOption(makeLeg(result, 1))).toBe(b)
    expect(chosenOption(makeLeg(result, null))).toBeNull()
    expect(chosenOption(makeLeg(null, 0))).toBeNull()
  })

  it('combine telt beide totalen in centen op', () => {
    // Bedragen met losse centen: een afrondings- of factor-100-fout valt dan op.
    const combined = combine(makeOption('KLM', 15650), makeOption('Finnair', 9875))

    expect(combined.totalCents).toBe(25525)
    expect(combined.legsCounted).toBe(2)
    expect(euro(combined.totalCents)).toBe('€ 255,25')
  })

  it('combine met één kant telt alleen die kant en meldt legsCounted 1', () => {
    expect(combine(makeOption('KLM', 15650), null)).toMatchObject({ totalCents: 15650, legsCounted: 1 })
    expect(combine(null, makeOption('KLM', 9875))).toMatchObject({ totalCents: 9875, legsCounted: 1 })
    expect(combine(null, null)).toMatchObject({ totalCents: 0, legsCounted: 0 })
  })

  it('combine zet excludesTransfer als één gekozen optie de transfer niet meetelt', () => {
    const plain = makeOption('SAS', 10000)
    const excl = makeOption('KLM', 10000, { trainCostIncluded: false })

    expect(combine(plain, plain).excludesTransfer).toBe(false)
    expect(combine(plain, excl).excludesTransfer).toBe(true)
    expect(combine(excl, null).excludesTransfer).toBe(true)
  })

  it('combine zet incomplete als één gekozen optie een onbekende treinprijs heeft', () => {
    const plain = makeOption('SAS', 10000)

    expect(combine(plain, plain).incomplete).toBe(false)
    expect(combine(makeOption('KLM', 1, { trainCostKnown: false }), plain).incomplete).toBe(true)
  })

  it('combine zet indicative als één gekozen optie indicatief is', () => {
    const plain = makeOption('SAS', 10000)

    expect(combine(plain, plain).indicative).toBe(false)
    expect(combine(plain, makeOption('KLM', 1, { indicative: true })).indicative).toBe(true)
  })
})

describe('validateDates', () => {
  it('accepteert een lege terugdatum', () => {
    expect(validateDates('2026-10-29', '')).toBeNull()
  })

  it('accepteert dezelfde dag heen en terug', () => {
    expect(validateDates('2026-10-29', '2026-10-29')).toBeNull()
  })

  it('weigert een terugdatum vóór de heendatum', () => {
    expect(validateDates('2026-10-29', '2026-10-28')).toBe('Terugdatum ligt vóór de heendatum.')
  })
})

describe('legForTrip', () => {
  const trip = (direction: 'nl-fi' | 'fi-nl') => ({ date: '2026-10-29', direction, traveller: 'chris' as const })

  it('koppelt nl-fi aan heen bij start NL en aan terug bij start FI', () => {
    expect(legForTrip(trip('nl-fi'), 'nl')).toBe('out')
    expect(legForTrip(trip('nl-fi'), 'fi')).toBe('back')
  })

  it('koppelt fi-nl aan terug bij start NL en aan heen bij start FI', () => {
    expect(legForTrip(trip('fi-nl'), 'nl')).toBe('back')
    expect(legForTrip(trip('fi-nl'), 'fi')).toBe('out')
  })
})
