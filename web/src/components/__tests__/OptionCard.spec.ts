import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import OptionCard from '../OptionCard.vue'
import type { Option } from '../../types'

const viaHelsinki: Option = {
  carrier: 'AY',
  carrierLabel: 'Finnair',
  pattern: 'via_hel_train',
  origin: 'AMS',
  destination: 'HEL',
  departAt: '2026-10-29T13:00:00+00:00',
  arriveAt: '2026-10-29T16:45:00+00:00',
  stops: 0,
  via: null,
  connectionMinutes: null,
  flightNumbers: ['AY842'],
  cost: {
    basePrice: 13400,
    trainCost: 2250,
    total: 15650,
    totalFormatted: '€ 156,50',
    indicative: false,
    trainCostKnown: true,  // nieuw veld
    trainCostIncluded: true,
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

    expect(rows).toHaveLength(2)
    expect(rows[0].text()).toContain('Vlucht')
    expect(rows[1].text()).toContain('Trein')

    // Ook de bedragen toetsen: met alleen de labels zou een kapotte
    // centen-omrekening onopgemerkt blijven, terwijl juist deze opbouw
    // moet laten zien waarom een optie wint.
    expect(rows[0].text()).toContain('€ 134,00')
    expect(rows[1].text()).toContain('€ 22,50')
  })

  it('telt de uitgesplitste bedragen op tot het getoonde totaal', () => {
    const card = mount(OptionCard, { props: { option: viaHelsinki } })
    const { basePrice, trainCost, total } = viaHelsinki.cost

    expect(basePrice + trainCost).toBe(total)
    expect(card.text()).toContain('€ 156,50')
  })

  it('laat de treinregel weg bij een doorgaand ticket', () => {
    const through: Option = {
      ...viaHelsinki,
      pattern: 'through',
      cost: { ...viaHelsinki.cost, trainCost: 0, total: 13400, totalFormatted: '€ 134,00' },
    }

    const card = mount(OptionCard, { props: { option: through } })

    expect(card.findAll('[data-testid="cost-row"]')).toHaveLength(1)
  })

  it('markeert een indicatieve prijs', () => {
    const klm: Option = {
      ...viaHelsinki,
      carrier: 'KL',
      carrierLabel: 'KLM',
      cost: { ...viaHelsinki.cost, indicative: true },
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

  it('toont vertrek- en aankomsttijd in de lokale tijd van dat vliegveld, niet in UTC', () => {
    // viaHelsinki: departAt 13:00 UTC vanaf AMS (CET, UTC+1 na de zomertijd),
    // arriveAt 16:45 UTC in HEL (EET, UTC+2). Puur UTC tonen (het oude gedrag)
    // is voor de reiziger fout: een Helsinki-tijd die 2 uur achterloopt.
    const card = mount(OptionCard, { props: { option: viaHelsinki } })

    expect(card.text()).toContain('14:00')
    expect(card.text()).toContain('18:45')
  })

  it('meldt dat de transfer niet is meegerekend als trainCostIncluded false is', () => {
    // Anders dan "onbekend": de optie blijft gerangschikt, maar het totaal is
    // onvolledig. Zonder deze melding is € 0,00 niet te onderscheiden van gratis.
    const excludedTrain: Option = {
      ...viaHelsinki,
      cost: {
        ...viaHelsinki.cost,
        trainCost: 0,
        trainCostKnown: true,
        trainCostIncluded: false,
      },
    }

    const card = mount(OptionCard, { props: { option: excludedTrain } })

    expect(card.find('[data-testid="train-excluded"]').text()).toBe('niet meegerekend')
    expect(card.find('[data-testid="excluded-notice"]').text()).toContain('exclusief transfer HEL→TKU')
  })

  it('meldt niets over een ontbrekende transfer als die wél is meegerekend', () => {
    const card = mount(OptionCard, { props: { option: viaHelsinki } })

    expect(card.find('[data-testid="excluded-notice"]').exists()).toBe(false)
  })

  it('toont "onbekend" in de treinregel als trainCostKnown false is', () => {
    const unknownTrain: Option = {
      ...viaHelsinki,
      cost: {
        ...viaHelsinki.cost,
        trainCost: 0,      // TotalCostCalculator zet trainCost op 0 als de prijs onbekend is
        trainCostKnown: false,
        trainCostIncluded: true,
        indicative: true,
      },
    }

    const card = mount(OptionCard, { props: { option: unknownTrain } })
    const rows = card.findAll('[data-testid="cost-row"]')
    const trainRow = rows.find(r => r.text().includes('Trein'))

    expect(trainRow).toBeDefined()
    expect(trainRow!.text()).toContain('onbekend')
    expect(trainRow!.text()).not.toContain('€ 0,00')
    expect(card.find('[data-testid="indicative"]').exists()).toBe(true)
  })

  it('klik op de kaart emit select', async () => {
    const card = mount(OptionCard, { props: { option: viaHelsinki } })

    await card.trigger('click')

    expect(card.emitted('select')).toHaveLength(1)
  })

  it('selected markeert de kaart (aria-pressed)', () => {
    const plain = mount(OptionCard, { props: { option: viaHelsinki } })
    const chosen = mount(OptionCard, { props: { option: viaHelsinki, selected: true } })

    expect(plain.attributes('aria-pressed')).toBe('false')
    expect(chosen.attributes('aria-pressed')).toBe('true')
  })
})
