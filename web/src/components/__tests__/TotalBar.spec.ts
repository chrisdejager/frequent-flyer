import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import TotalBar from '../TotalBar.vue'
import { makeOption } from '../../__tests__/fixtures'

describe('TotalBar', () => {
  it('shows the sum of outbound and return as euro', () => {
    const bar = mount(TotalBar, {
      props: { out: makeOption('KLM', 15650), back: makeOption('Finnair', 9875), backRequested: true, isDefault: true },
    })

    expect(bar.find('[data-testid="total-sum"]').text()).toBe('€ 255,25')
    expect(bar.text()).toContain('KLM')
    expect(bar.text()).toContain('Finnair')
  })

  it('reports "outbound only" if there is no return date', () => {
    const oneWay = mount(TotalBar, { props: { out: makeOption('KLM', 100), back: null, backRequested: false, isDefault: true } })
    const roundTrip = mount(TotalBar, { props: { out: makeOption('KLM', 100), back: makeOption('SAS', 100), backRequested: true, isDefault: true } })

    expect(oneWay.text()).toContain('Outbound only')
    expect(roundTrip.text()).not.toContain('Outbound only')
  })

  it('says "your choice" instead of "cheapest combination" if the choice differs', () => {
    const own = mount(TotalBar, { props: { out: makeOption('KLM', 100), back: null, backRequested: false, isDefault: false } })

    expect(own.text()).toContain('Your choice')
    expect(own.text()).not.toContain('Cheapest')
  })

  it('reports that return is missing if there was a return date but no choice', () => {
    const missing = mount(TotalBar, { props: { out: makeOption('KLM', 100), back: null, backRequested: true, isDefault: true } })
    const oneWay = mount(TotalBar, { props: { out: makeOption('KLM', 100), back: null, backRequested: false, isDefault: true } })

    expect(missing.text()).toContain('Return missing')
    expect(oneWay.text()).not.toContain('missing')
  })

  it('reports that outbound is missing if only return is chosen', () => {
    const bar = mount(TotalBar, { props: { out: null, back: makeOption('KLM', 100), backRequested: true, isDefault: true } })

    expect(bar.text()).toContain('Outbound missing')
  })

  it('while searching shows no amount and no "missing": the side is coming, not gone', () => {
    const bar = mount(TotalBar, {
      props: { out: makeOption('KLM', 100), back: null, backRequested: true, isDefault: true, searching: true },
    })

    expect(bar.find('[data-testid="total-sum"]').text()).toBe('…')
    expect(bar.text()).toContain('Searching')
    expect(bar.text()).not.toContain('missing')
  })
})
