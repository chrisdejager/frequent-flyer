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
})
