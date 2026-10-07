import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import LegColumn from '../LegColumn.vue'
import { makeLeg, makeOption, makeResult } from '../../__tests__/fixtures'

describe('LegColumn', () => {
  it('shows ranked options before the separator and unranked after', () => {
    // The unranked is deliberately first in the input: the column must
    // split itself, not follow the order of input.
    const leg = makeLeg(makeResult([
      makeOption('KLM', 19900, { trainCostKnown: false }),
      makeOption('Finnair', 15650),
    ]), 1)
    const html = mount(LegColumn, { props: { title: 'Outbound', leg } }).html()

    const finnairPos = html.indexOf('Finnair')
    const noticePos = html.indexOf('unranked-notice')
    const klmPos = html.indexOf('KLM')

    expect(finnairPos).toBeGreaterThanOrEqual(0)
    expect(finnairPos).toBeLessThan(noticePos)
    expect(noticePos).toBeLessThan(klmPos)
  })

  it('emits the original index when an unranked option is clicked', async () => {
    // Input: [ranked, unranked, ranked]. Display: 0, 2, | 1.
    // The third card on screen is thus index 1 — not 2.
    const leg = makeLeg(makeResult([
      makeOption('Finnair', 15650),
      makeOption('KLM', 19900, { trainCostKnown: false }),
      makeOption('SAS', 17000),
    ]), 0)
    const column = mount(LegColumn, { props: { title: 'Outbound', leg } })

    const cards = column.findAll('article')
    expect(cards[2].text()).toContain('KLM')

    await cards[2].trigger('click')

    expect(column.emitted('select')).toEqual([[1]])
  })

  it('marks only the chosen option', () => {
    const leg = makeLeg(makeResult([makeOption('Finnair', 15650), makeOption('SAS', 17000)]), 1)
    const cards = mount(LegColumn, { props: { title: 'Outbound', leg } }).findAll('article')

    expect(cards.map(c => c.attributes('aria-pressed'))).toEqual(['false', 'true'])
  })

  it('shows the error message of only this leg', () => {
    const leg = { ...makeLeg(null, null), error: 'ignav returned 503' }

    expect(mount(LegColumn, { props: { title: 'Return', leg } }).text()).toContain('ignav returned 503')
  })

  it('shows "no options" on empty result', () => {
    const leg = makeLeg(makeResult([]), null)

    expect(mount(LegColumn, { props: { title: 'Return', leg } }).text()).toContain('No options')
  })
})
