import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import TotalBar from '../TotalBar.vue'
import { makeOption } from '../../__tests__/fixtures'

describe('TotalBar', () => {
  it('toont de som van heen en terug als euro', () => {
    const bar = mount(TotalBar, {
      props: { out: makeOption('KLM', 15650), back: makeOption('Finnair', 9875), backRequested: true },
    })

    expect(bar.find('[data-testid="total-sum"]').text()).toBe('€ 255,25')
    expect(bar.text()).toContain('KLM')
    expect(bar.text()).toContain('Finnair')
  })

  it('toont "exclusief transfer HEL→TKU" als een gekozen optie dat vraagt', () => {
    const plain = mount(TotalBar, {
      props: { out: makeOption('SAS', 100), back: makeOption('SAS', 100), backRequested: true },
    })
    const excl = mount(TotalBar, {
      props: { out: makeOption('SAS', 100), back: makeOption('KLM', 100, { trainCostIncluded: false }), backRequested: true },
    })

    expect(plain.text()).not.toContain('exclusief transfer')
    expect(excl.text()).toContain('exclusief transfer HEL→TKU')
  })
})
