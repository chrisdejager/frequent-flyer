import { beforeEach, describe, expect, it, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import App from '../../App.vue'
import type { SearchResult, Trip } from '../../types'

vi.mock('../../api', () => ({
  plannedTrips: vi.fn(async (): Promise<Trip[]> => []),
  search: vi.fn(async (): Promise<SearchResult> => mockResult),
}))

import { search } from '../../api'
import { makeOption, makeResult } from '../../__tests__/fixtures'

const rankedOption = {
  carrier: 'AY',
  carrierLabel: 'Finnair',
  pattern: 'via_hel_train' as const,
  origin: 'AMS',
  destination: 'HEL',
  departAt: '2026-10-29T06:00:00+00:00',
  arriveAt: '2026-10-29T14:00:00+00:00',
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
    trainCostKnown: true,
    trainCostIncluded: true,
  },
}

const unrankedOption = {
  carrier: 'KL',
  carrierLabel: 'KLM',
  pattern: 'through' as const,
  origin: 'AMS',
  destination: 'TKU',
  departAt: '2026-10-29T08:00:00+00:00',
  arriveAt: '2026-10-29T18:00:00+00:00',
  stops: 1,
  via: 'AMS',
  connectionMinutes: 60,
  flightNumbers: ['KL1234', 'KL5678'],
  cost: {
    basePrice: 19900,
    trainCost: 0,
    total: 19900,
    totalFormatted: '€ 199,00',
    indicative: true,
    trainCostKnown: false,
    trainCostIncluded: true,
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

    expect(vi.mocked(search)).toHaveBeenCalled()
  })

  it('zoekt heen en terug met de juiste richting en datum per kant', async () => {
    const wrapper = mount(App)
    await flushPromises()

    await wrapper.find('[data-testid="out-date"]').setValue('2026-10-29')
    await wrapper.find('[data-testid="back-date"]').setValue('2026-11-09')
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(vi.mocked(search)).toHaveBeenCalledTimes(2)
    expect(vi.mocked(search)).toHaveBeenCalledWith('2026-10-29', 'nl-fi', 'partner')
    expect(vi.mocked(search)).toHaveBeenCalledWith('2026-11-09', 'fi-nl', 'partner')

    // Beide kanten krijgen een eigen kolom met hun eigen opties.
    const columns = wrapper.findAll('[data-testid="leg-column"]')
    expect(columns).toHaveLength(2)
    expect(columns[0].text()).toContain('Heen')
    expect(columns[0].text()).toContain('Finnair')
    expect(columns[1].text()).toContain('Terug')
    expect(columns[1].text()).toContain('Finnair')

    expect(wrapper.find('[data-testid="total-bar"]').exists()).toBe(true)
  })
})

async function searchWith(outDate: string, backDate: string) {
  const wrapper = mount(App)
  await flushPromises()
  await wrapper.find('[data-testid="out-date"]').setValue(outDate)
  await wrapper.find('[data-testid="back-date"]').setValue(backDate)
  await wrapper.find('form').trigger('submit')
  await flushPromises()

  return wrapper
}

describe('App — totaal', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('standaard is het totaal goedkoopste heen + goedkoopste terug', async () => {
    // Per richting een eigen resultaat, met een ongerangschikte optie die
    // goedkoper is maar niet als standaard gekozen mag worden.
    vi.mocked(search).mockImplementation(async (_date, direction) =>
      direction === 'nl-fi'
        ? makeResult([makeOption('KLM', 15650), makeOption('SAS', 17000)])
        : makeResult([makeOption('Goedkoop', 100, { trainCostKnown: false }), makeOption('Finnair', 9875)]),
    )

    const wrapper = await searchWith('2026-10-29', '2026-11-09')

    expect(wrapper.find('[data-testid="total-sum"]').text()).toBe('€ 255,25')
    expect(wrapper.find('[data-testid="total-bar"]').text()).toContain('Goedkoopste combinatie')
  })

  it('een andere optie aanklikken past het totaal aan zonder nieuwe zoekactie', async () => {
    vi.mocked(search).mockImplementation(async (_date, direction) =>
      direction === 'nl-fi'
        ? makeResult([makeOption('KLM', 15650), makeOption('SAS', 17000)])
        : makeResult([makeOption('Finnair', 9875)]),
    )

    const wrapper = await searchWith('2026-10-29', '2026-11-09')
    expect(vi.mocked(search)).toHaveBeenCalledTimes(2)

    // Tweede kaart van de heenkolom: SAS € 170,00.
    const outColumn = wrapper.findAll('[data-testid="leg-column"]')[0]
    await outColumn.findAll('article')[1].trigger('click')

    const bar = wrapper.find('[data-testid="total-bar"]')
    expect(wrapper.find('[data-testid="total-sum"]').text()).toBe('€ 268,75')
    expect(bar.text()).toContain('SAS')
    // Niet meer de goedkoopste — dan mag de balk dat ook niet beweren.
    expect(bar.text()).toContain('Jouw keuze')
    expect(bar.text()).not.toContain('Goedkoopste combinatie')
    expect(vi.mocked(search)).toHaveBeenCalledTimes(2)

    // Terug naar de goedkoopste: de balk zegt het weer.
    await outColumn.findAll('article')[0].trigger('click')
    expect(bar.text()).toContain('Goedkoopste combinatie')
  })
})

describe('App — datums', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    vi.mocked(search).mockImplementation(async () => makeResult([makeOption('KLM', 15650)]))
  })

  it('zoekt maar één keer zonder terugdatum', async () => {
    const wrapper = await searchWith('2026-10-29', '')

    expect(vi.mocked(search)).toHaveBeenCalledOnce()
    expect(vi.mocked(search)).toHaveBeenCalledWith('2026-10-29', 'nl-fi', 'partner')
    expect(wrapper.findAll('[data-testid="leg-column"]')).toHaveLength(1)
    expect(wrapper.find('[data-testid="total-sum"]').text()).toBe('€ 156,50')
    expect(wrapper.find('[data-testid="total-bar"]').text()).toContain('Alleen heenreis')
  })

  it('zoekt niet bij een terugdatum vóór de heendatum en toont een melding', async () => {
    const wrapper = await searchWith('2026-11-09', '2026-10-29')

    expect(vi.mocked(search)).not.toHaveBeenCalled()
    expect(wrapper.find('[data-testid="form-error"]').text()).toContain('Terugdatum ligt vóór de heendatum')
    expect(wrapper.find('[data-testid="total-bar"]').exists()).toBe(false)
  })

  it('een terugdatum die ná het zoeken wordt ingevuld toont geen lege terugkolom', async () => {
    const wrapper = await searchWith('2026-10-29', '')
    await wrapper.find('[data-testid="back-date"]').setValue('2026-11-09')

    expect(wrapper.findAll('[data-testid="leg-column"]')).toHaveLength(1)
  })
})
