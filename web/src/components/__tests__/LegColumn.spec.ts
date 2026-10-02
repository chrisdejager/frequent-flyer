import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import LegColumn from '../LegColumn.vue'
import { makeLeg, makeOption, makeResult } from '../../__tests__/fixtures'

describe('LegColumn', () => {
  it('toont gerangschikte opties vóór de scheidingslijn en ongerangschikte erna', () => {
    // De ongerangschikte staat in de invoer bewust vóóraan: de kolom moet
    // zelf splitsen, niet de volgorde van binnenkomst volgen.
    const leg = makeLeg(makeResult([
      makeOption('KLM', 19900, { trainCostKnown: false }),
      makeOption('Finnair', 15650),
    ]), 1)
    const html = mount(LegColumn, { props: { title: 'Heen', leg } }).html()

    const finnairPos = html.indexOf('Finnair')
    const noticePos = html.indexOf('unranked-notice')
    const klmPos = html.indexOf('KLM')

    expect(finnairPos).toBeGreaterThanOrEqual(0)
    expect(finnairPos).toBeLessThan(noticePos)
    expect(noticePos).toBeLessThan(klmPos)
  })

  it('emit de oorspronkelijke index als een ongerangschikte optie wordt aangeklikt', async () => {
    // Invoer: [gerangschikt, ongerangschikt, gerangschikt]. Weergave: 0, 2, | 1.
    // De derde kaart op het scherm is dus index 1 — niet 2.
    const leg = makeLeg(makeResult([
      makeOption('Finnair', 15650),
      makeOption('KLM', 19900, { trainCostKnown: false }),
      makeOption('SAS', 17000),
    ]), 0)
    const column = mount(LegColumn, { props: { title: 'Heen', leg } })

    const cards = column.findAll('article')
    expect(cards[2].text()).toContain('KLM')

    await cards[2].trigger('click')

    expect(column.emitted('select')).toEqual([[1]])
  })

  it('markeert alleen de gekozen optie', () => {
    const leg = makeLeg(makeResult([makeOption('Finnair', 15650), makeOption('SAS', 17000)]), 1)
    const cards = mount(LegColumn, { props: { title: 'Heen', leg } }).findAll('article')

    expect(cards.map(c => c.attributes('aria-pressed'))).toEqual(['false', 'true'])
  })
})
