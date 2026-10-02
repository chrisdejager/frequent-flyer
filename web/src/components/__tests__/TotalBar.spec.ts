import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import TotalBar from '../TotalBar.vue'
import { makeOption } from '../../__tests__/fixtures'

describe('TotalBar', () => {
  it('toont de som van heen en terug als euro', () => {
    const bar = mount(TotalBar, {
      props: { out: makeOption('KLM', 15650), back: makeOption('Finnair', 9875), backRequested: true, isDefault: true },
    })

    expect(bar.find('[data-testid="total-sum"]').text()).toBe('€ 255,25')
    expect(bar.text()).toContain('KLM')
    expect(bar.text()).toContain('Finnair')
  })

  it('toont "exclusief transfer HEL→TKU" als een gekozen optie dat vraagt', () => {
    const plain = mount(TotalBar, {
      props: { out: makeOption('SAS', 100), back: makeOption('SAS', 100), backRequested: true, isDefault: true },
    })
    const excl = mount(TotalBar, {
      props: { out: makeOption('SAS', 100), back: makeOption('KLM', 100, { trainCostIncluded: false }), backRequested: true, isDefault: true },
    })

    expect(plain.text()).not.toContain('exclusief transfer')
    expect(excl.text()).toContain('exclusief transfer HEL→TKU')
  })

  it('meldt "alleen heenreis" als er geen terugdatum is', () => {
    const oneWay = mount(TotalBar, { props: { out: makeOption('KLM', 100), back: null, backRequested: false, isDefault: true } })
    const roundTrip = mount(TotalBar, { props: { out: makeOption('KLM', 100), back: makeOption('SAS', 100), backRequested: true, isDefault: true } })

    expect(oneWay.text()).toContain('Alleen heenreis')
    expect(roundTrip.text()).not.toContain('Alleen heenreis')
  })

  it('zegt "Jouw keuze" in plaats van "Goedkoopste combinatie" als de keuze afwijkt', () => {
    const own = mount(TotalBar, { props: { out: makeOption('KLM', 100), back: null, backRequested: false, isDefault: false } })

    expect(own.text()).toContain('Jouw keuze')
    expect(own.text()).not.toContain('Goedkoopste')
  })
})
