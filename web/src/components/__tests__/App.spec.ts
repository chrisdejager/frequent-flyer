import { beforeEach, describe, expect, it, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import App from '../../App.vue'
import type { SearchResult, Trip } from '../../types'

vi.mock('../../api', () => ({
  plannedTrips: vi.fn(async (): Promise<Trip[]> => []),
  search: vi.fn(async (): Promise<SearchResult> => mockResult),
}))

import { search } from '../../api'

const rankedOption = {
  carrier: 'AY',
  carrierLabel: 'Finnair',
  pattern: 'via_hel_train' as const,
  departAt: '2026-10-29T06:00:00+00:00',
  arriveAt: '2026-10-29T14:00:00+00:00',
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
    trainCostKnown: true,
  },
}

const unrankedOption = {
  carrier: 'KL',
  carrierLabel: 'KLM',
  pattern: 'through' as const,
  departAt: '2026-10-29T08:00:00+00:00',
  arriveAt: '2026-10-29T18:00:00+00:00',
  stops: 1,
  via: 'AMS',
  connectionMinutes: 60,
  flightNumbers: ['KL1234', 'KL5678'],
  cost: {
    basePrice: 19900,
    baggageCost: 0,
    trainCost: 0,
    total: 19900,
    totalFormatted: '€ 199,00',
    indicative: true,
    trainCostKnown: false,
  },
}

const mockResult: SearchResult = {
  traveller: { id: 'chris', name: 'Chris' },
  searchedAt: '2026-09-17T10:00:00Z',
  options: [rankedOption, unrankedOption],
}

describe('App', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('toont gerangschikte opties vóór ongerangschikte en toont de scheidingslijn', async () => {
    const wrapper = mount(App)
    await flushPromises()

    // Dien het formulier in om zoekresultaten te laden
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    const unrankedNotice = wrapper.find('[data-testid="unranked-notice"]')

    // De ongerangschikte-melding moet zichtbaar zijn
    expect(unrankedNotice.exists()).toBe(true)
    expect(unrankedNotice.text()).toContain('Niet gerangschikt')

    // De scheidingsregel moet écht tússen de twee groepen staan, niet er
    // willekeurig omheen: gerangschikt (Finnair) → notice → ongerangschikt (KLM).
    // Alleen "Finnair vóór KLM" toetsen zou ook groen blijven als de notice
    // boven of onder beide kaarten belandt, en dat is precies de semantiek
    // die deze test moet vastleggen.
    const html = wrapper.html()
    const finnairPos = html.indexOf('Finnair')
    const noticePos = html.indexOf('unranked-notice')
    const klmPos = html.indexOf('KLM')

    expect(finnairPos).toBeGreaterThanOrEqual(0)
    expect(noticePos).toBeGreaterThanOrEqual(0)
    expect(klmPos).toBeGreaterThanOrEqual(0)

    expect(finnairPos).toBeLessThan(noticePos)
    expect(noticePos).toBeLessThan(klmPos)
  })

  it('toont de naam van de reiziger na een zoekopdracht', async () => {
    const wrapper = mount(App)
    await flushPromises()

    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(wrapper.find('.traveller-label').exists()).toBe(true)
    expect(wrapper.find('.traveller-label').text()).toContain('Chris')
  })

  it('gaat het netwerk niet op — api.search wordt gemockt aangeroepen', async () => {
    const wrapper = mount(App)
    await flushPromises()

    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(vi.mocked(search)).toHaveBeenCalledOnce()
  })
})
