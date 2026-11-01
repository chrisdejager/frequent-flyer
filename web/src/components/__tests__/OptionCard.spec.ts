import { afterEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import OptionCard from '../OptionCard.vue'
import { bookingPageUrl } from '../../booking'
import type { Option } from '../../types'

const toHelsinki: Option = {
  carrier: 'AY',
  carrierLabel: 'Finnair',
  pattern: 'helsinki',
  origin: 'AMS',
  destination: 'HEL',
  departAt: '2026-10-29T13:00:00+00:00',
  arriveAt: '2026-10-29T16:45:00+00:00',
  stops: 0,
  via: null,
  connectionMinutes: null,
  flightNumbers: ['AY842'],
  bookingRef: '0ea5aa41342f00000056346ef96d746d',
  observedAt: null,
  cost: {
    basePrice: 15650,
    total: 15650,
    totalFormatted: '€ 156,50',
  },
}

describe('OptionCard', () => {
  it('shows the total price', () => {
    const card = mount(OptionCard, { props: { option: toHelsinki } })

    expect(card.text()).toContain('€ 156,50')
    expect(card.text()).toContain('Finnair')
  })

  it('shows the flight price as the only cost line', () => {
    const card = mount(OptionCard, { props: { option: toHelsinki } })
    const rows = card.findAll('[data-testid="cost-row"]')

    expect(rows).toHaveLength(1)
    expect(rows[0].text()).toContain('Flight')
    // The amount too: with only the label, a broken cents conversion would go unnoticed.
    expect(rows[0].text()).toContain('€ 156,50')
  })

  it('shows the connection if there is one', () => {
    const through: Option = {
      ...toHelsinki,
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
    // toHelsinki: departAt 13:00 UTC from AMS (CET, UTC+1 after daylight saving),
    // arriveAt 16:45 UTC in HEL (EET, UTC+2). Showing only UTC (the old behavior)
    // is wrong for the traveller: a Helsinki time that's 2 hours behind.
    const card = mount(OptionCard, { props: { option: toHelsinki } })

    expect(card.text()).toContain('14:00')
    expect(card.text()).toContain('18:45')
  })

  it('click on the card emits select', async () => {
    const card = mount(OptionCard, { props: { option: toHelsinki } })

    await card.trigger('click')

    expect(card.emitted('select')).toHaveLength(1)
  })

  it('selected marks the card (aria-pressed)', () => {
    const plain = mount(OptionCard, { props: { option: toHelsinki } })
    const chosen = mount(OptionCard, { props: { option: toHelsinki, selected: true } })

    expect(plain.attributes('aria-pressed')).toBe('false')
    expect(chosen.attributes('aria-pressed')).toBe('true')
  })

  describe('Book button', () => {
    afterEach(() => vi.restoreAllMocks())

    it('opens the booking page in a new tab, without opener', async () => {
      const open = vi.spyOn(window, 'open').mockReturnValue(null)
      const card = mount(OptionCard, { props: { option: toHelsinki } })

      await card.find('[data-testid="book"]').trigger('click')

      expect(open).toHaveBeenCalledWith(bookingPageUrl(toHelsinki), '_blank', 'noopener')
    })

    it('clicking Book does not select the row', async () => {
      vi.spyOn(window, 'open').mockReturnValue(null)
      const card = mount(OptionCard, { props: { option: toHelsinki } })

      await card.find('[data-testid="book"]').trigger('click')

      expect(card.emitted('select')).toBeUndefined()
    })

    it('Enter or space on Book does not select the row', async () => {
      vi.spyOn(window, 'open').mockReturnValue(null)
      const card = mount(OptionCard, { props: { option: toHelsinki } })
      const button = card.find('[data-testid="book"]')

      await button.trigger('keydown', { key: 'Enter' })
      await button.trigger('keydown', { key: ' ' })

      expect(card.emitted('select')).toBeUndefined()
    })

    it('is missing without bookingRef', () => {
      const card = mount(OptionCard, { props: { option: { ...toHelsinki, bookingRef: null } } })

      expect(card.find('[data-testid="book"]').exists()).toBe(false)
    })
  })
})
