import { afterEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import OptionCard from '../OptionCard.vue'
import { bookingPageUrl } from '../../booking'
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
  bookingRef: '0ea5aa41342f00000056346ef96d746d',
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
  it('shows the total price', () => {
    const card = mount(OptionCard, { props: { option: viaHelsinki } })

    expect(card.text()).toContain('€ 156,50')
    expect(card.text()).toContain('Finnair')
  })

  it('splits the price so you can see why an option wins', () => {
    const card = mount(OptionCard, { props: { option: viaHelsinki } })
    const rows = card.findAll('[data-testid="cost-row"]')

    expect(rows).toHaveLength(2)
    expect(rows[0].text()).toContain('Flight')
    expect(rows[1].text()).toContain('Train')

    // Also check the amounts: with only the labels, a broken cents conversion
    // would go unnoticed, while this breakdown should show why an option wins.
    expect(rows[0].text()).toContain('€ 134,00')
    expect(rows[1].text()).toContain('€ 22,50')
  })

  it('adds up the split amounts to the displayed total', () => {
    const card = mount(OptionCard, { props: { option: viaHelsinki } })
    const { basePrice, trainCost, total } = viaHelsinki.cost

    expect(basePrice + trainCost).toBe(total)
    expect(card.text()).toContain('€ 156,50')
  })

  it('omits the train line for through-ticketed flights', () => {
    const through: Option = {
      ...viaHelsinki,
      pattern: 'through',
      cost: { ...viaHelsinki.cost, trainCost: 0, total: 13400, totalFormatted: '€ 134,00' },
    }

    const card = mount(OptionCard, { props: { option: through } })

    expect(card.findAll('[data-testid="cost-row"]')).toHaveLength(1)
  })

  it('marks an indicative price', () => {
    const klm: Option = {
      ...viaHelsinki,
      carrier: 'KL',
      carrierLabel: 'KLM',
      cost: { ...viaHelsinki.cost, indicative: true },
    }

    const card = mount(OptionCard, { props: { option: klm } })

    expect(card.find('[data-testid="indicative"]').exists()).toBe(true)
    expect(card.text()).toContain('indicative')
  })

  it('does not mark a fixed price', () => {
    const card = mount(OptionCard, { props: { option: viaHelsinki } })

    expect(card.find('[data-testid="indicative"]').exists()).toBe(false)
  })

  it('shows the connection if there is one', () => {
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

  it('shows departure and arrival times in local time of that airport, not UTC', () => {
    // viaHelsinki: departAt 13:00 UTC from AMS (CET, UTC+1 after daylight saving),
    // arriveAt 16:45 UTC in HEL (EET, UTC+2). Showing only UTC (the old behavior)
    // is wrong for the traveller: a Helsinki time that's 2 hours behind.
    const card = mount(OptionCard, { props: { option: viaHelsinki } })

    expect(card.text()).toContain('14:00')
    expect(card.text()).toContain('18:45')
  })

  it('reports that transfer is not included if trainCostIncluded is false', () => {
    // Unlike "unknown": the option remains ranked, but the total is incomplete.
    // Without this notice, € 0.00 cannot be distinguished from free.
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

    expect(card.find('[data-testid="train-excluded"]').text()).toBe('not included')
    expect(card.find('[data-testid="excluded-notice"]').text()).toContain('excludes transfer HEL→TKU')
  })

  it('reports nothing about missing transfer if it is included', () => {
    const card = mount(OptionCard, { props: { option: viaHelsinki } })

    expect(card.find('[data-testid="excluded-notice"]').exists()).toBe(false)
  })

  it('shows "unknown" in the train line if trainCostKnown is false', () => {
    const unknownTrain: Option = {
      ...viaHelsinki,
      cost: {
        ...viaHelsinki.cost,
        trainCost: 0,      // TotalCostCalculator sets trainCost to 0 if price is unknown
        trainCostKnown: false,
        trainCostIncluded: true,
        indicative: true,
      },
    }

    const card = mount(OptionCard, { props: { option: unknownTrain } })
    const rows = card.findAll('[data-testid="cost-row"]')
    const trainRow = rows.find(r => r.text().includes('Train'))

    expect(trainRow).toBeDefined()
    expect(trainRow!.text()).toContain('unknown')
    expect(trainRow!.text()).not.toContain('€ 0,00')
    expect(card.find('[data-testid="indicative"]').exists()).toBe(true)
  })

  it('click on the card emits select', async () => {
    const card = mount(OptionCard, { props: { option: viaHelsinki } })

    await card.trigger('click')

    expect(card.emitted('select')).toHaveLength(1)
  })

  it('selected marks the card (aria-pressed)', () => {
    const plain = mount(OptionCard, { props: { option: viaHelsinki } })
    const chosen = mount(OptionCard, { props: { option: viaHelsinki, selected: true } })

    expect(plain.attributes('aria-pressed')).toBe('false')
    expect(chosen.attributes('aria-pressed')).toBe('true')
  })

  describe('Book button', () => {
    afterEach(() => vi.restoreAllMocks())

    it('opens the booking page in a new tab, without opener', async () => {
      const open = vi.spyOn(window, 'open').mockReturnValue(null)
      const card = mount(OptionCard, { props: { option: viaHelsinki } })

      await card.find('[data-testid="book"]').trigger('click')

      expect(open).toHaveBeenCalledWith(bookingPageUrl(viaHelsinki), '_blank', 'noopener')
    })

    it('clicking Book does not select the row', async () => {
      vi.spyOn(window, 'open').mockReturnValue(null)
      const card = mount(OptionCard, { props: { option: viaHelsinki } })

      await card.find('[data-testid="book"]').trigger('click')

      expect(card.emitted('select')).toBeUndefined()
    })

    it('Enter or space on Book does not select the row', async () => {
      vi.spyOn(window, 'open').mockReturnValue(null)
      const card = mount(OptionCard, { props: { option: viaHelsinki } })
      const button = card.find('[data-testid="book"]')

      await button.trigger('keydown', { key: 'Enter' })
      await button.trigger('keydown', { key: ' ' })

      expect(card.emitted('select')).toBeUndefined()
    })

    it('is missing without bookingRef', () => {
      const card = mount(OptionCard, { props: { option: { ...viaHelsinki, bookingRef: null } } })

      expect(card.find('[data-testid="book"]').exists()).toBe(false)
    })
  })
})
