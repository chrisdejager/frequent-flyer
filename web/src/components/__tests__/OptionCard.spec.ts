import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import OptionCard from '../OptionCard.vue'
import type { Option } from '../../types'

const viaHelsinki: Option = {
  carrier: 'AY',
  carrierLabel: 'Finnair',
  pattern: 'via_hel_train',
  departAt: '2026-10-29T13:00:00+00:00',
  arriveAt: '2026-10-29T16:45:00+00:00',
  stops: 0,
  via: null,
  connectionMinutes: null,
  flightNumbers: ['AY842'],
  cost: {
    basePrice: 13400,
    baggageCost: 0,
    trainCost: 2250,
    total: 15650,
    totalFormatted: '€ 156,50',
    indicative: false,
  },
}

describe('OptionCard', () => {
  it('toont de totaalprijs', () => {
    const card = mount(OptionCard, { props: { option: viaHelsinki } })

    expect(card.text()).toContain('€ 156,50')
    expect(card.text()).toContain('Finnair')
  })

  it('splitst de prijs uit zodat zichtbaar is waarom een optie wint', () => {
    const card = mount(OptionCard, { props: { option: viaHelsinki } })
    const rows = card.findAll('[data-testid="cost-row"]')

    expect(rows).toHaveLength(3)
    expect(rows[0].text()).toContain('Vlucht')
    expect(rows[1].text()).toContain('Bagage')
    expect(rows[2].text()).toContain('Trein')
  })

  it('laat de treinregel weg bij een doorgaand ticket', () => {
    const through: Option = {
      ...viaHelsinki,
      pattern: 'through',
      cost: { ...viaHelsinki.cost, trainCost: 0, total: 13400, totalFormatted: '€ 134,00' },
    }

    const card = mount(OptionCard, { props: { option: through } })

    expect(card.findAll('[data-testid="cost-row"]')).toHaveLength(2)
  })

  it('markeert een indicatieve prijs', () => {
    const klm: Option = {
      ...viaHelsinki,
      carrier: 'KL',
      carrierLabel: 'KLM',
      cost: { ...viaHelsinki.cost, baggageCost: 4000, indicative: true },
    }

    const card = mount(OptionCard, { props: { option: klm } })

    expect(card.find('[data-testid="indicative"]').exists()).toBe(true)
    expect(card.text()).toContain('indicatief')
  })

  it('markeert een vaste prijs niet', () => {
    const card = mount(OptionCard, { props: { option: viaHelsinki } })

    expect(card.find('[data-testid="indicative"]').exists()).toBe(false)
  })

  it('toont de overstap als die er is', () => {
    const through: Option = {
      ...viaHelsinki,
      carrier: 'SK',
      carrierLabel: 'SAS',
      pattern: 'through',
      stops: 1,
      via: 'ARN',
      connectionMinutes: 90,
      flightNumbers: ['SK552', 'SK721'],
    }

    const card = mount(OptionCard, { props: { option: through } })

    expect(card.text()).toContain('ARN')
    expect(card.text()).toContain('90 min')
  })

  it('toont de tijden zoals de bron ze gaf, niet omgerekend naar de browserzone', () => {
    const card = mount(OptionCard, { props: { option: viaHelsinki } })

    expect(card.text()).toContain('13:00')
    expect(card.text()).toContain('16:45')
  })
})
