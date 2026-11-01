import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import LegColumn from '../LegColumn.vue'
import { makeLeg, makeOption, makeResult } from '../../__tests__/fixtures'

describe('LegColumn', () => {
  it('shows the options in the order the API sends them, and emits the clicked index', async () => {
    const leg = makeLeg(makeResult([makeOption('Finnair', 15650), makeOption('SAS', 17000)]), 0)
    const column = mount(LegColumn, { props: { title: 'Outbound', leg } })

    const cards = column.findAll('article')
    expect(cards.map(c => c.text().includes('Finnair'))).toEqual([true, false])

    await cards[1].trigger('click')

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

  it('says how old the prices are when the source reports it', () => {
    vi.useFakeTimers({ toFake: ['Date'] })
    vi.setSystemTime(new Date('2026-10-20T19:10:00Z'))

    const stale = { ...makeOption('KLM', 15650), observedAt: '2026-10-20T14:05:00+00:00' }
    const column = mount(LegColumn, { props: { title: 'Outbound', leg: makeLeg(makeResult([stale]), 0) } })

    expect(column.find('[data-testid="prices-as-of"]').text()).toContain('5 h old')
    vi.useRealTimers()
  })

  it('says nothing about age when no option reports it: unknown is not fresh', () => {
    const column = mount(LegColumn, { props: { title: 'Outbound', leg: makeLeg(makeResult([makeOption('KLM', 15650)]), 0) } })

    expect(column.find('[data-testid="prices-as-of"]').exists()).toBe(false)
  })

  it('emits a day shift from the arrow buttons, and only when allowed', async () => {
    const leg = makeLeg(makeResult([makeOption('KLM', 15650)]), 0)
    const column = mount(LegColumn, { props: { title: 'Outbound', leg, canPrev: false, canNext: true } })

    expect(column.find('[data-testid="prev-day"]').attributes('disabled')).toBeDefined()

    await column.find('[data-testid="next-day"]').trigger('click')

    expect(column.emitted('shift')).toEqual([[1]])
  })
})
