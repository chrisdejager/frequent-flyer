import { beforeEach, describe, expect, it, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import App from '../../App.vue'
import type { SearchResult, Trip } from '../../types'

vi.mock('../../api', () => ({
  plannedTrips: vi.fn(async (): Promise<Trip[]> => []),
  search: vi.fn(async (): Promise<SearchResult> => mockResult),
}))

import { plannedTrips, search } from '../../api'
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
  bookingRef: null,
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
  bookingRef: null,
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

  it('shows ranked options before unranked and shows the separator line', async () => {
    const wrapper = mount(App)
    await flushPromises()

    // Submit the form to load search results
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    const unrankedNotice = wrapper.find('[data-testid="unranked-notice"]')

    // The unranked notice must be visible
    expect(unrankedNotice.exists()).toBe(true)
    expect(unrankedNotice.text()).toContain('Unranked')

    // The separator must really be between the two groups, not
    // randomly around them: ranked (Finnair) → notice → unranked (KLM).
    // Only testing "Finnair before KLM" would also pass if the notice
    // ends up above or below both cards, and that's exactly the semantics
    // this test must capture.
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

  it('shows the traveller name after a search', async () => {
    const wrapper = mount(App)
    await flushPromises()

    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(wrapper.find('.traveller-label').exists()).toBe(true)
    expect(wrapper.find('.traveller-label').text()).toContain('Chris')
  })

  it('does not go on the network — api.search is called mocked', async () => {
    const wrapper = mount(App)
    await flushPromises()

    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(vi.mocked(search)).toHaveBeenCalled()
  })

  it('searches outbound and return with the correct direction and date per leg', async () => {
    const wrapper = mount(App)
    await flushPromises()

    await wrapper.find('[data-testid="out-date"]').setValue('2026-10-29')
    await wrapper.find('[data-testid="back-date"]').setValue('2026-11-09')
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(vi.mocked(search)).toHaveBeenCalledTimes(2)
    expect(vi.mocked(search)).toHaveBeenCalledWith('2026-10-29', 'nl-fi', 'partner')
    expect(vi.mocked(search)).toHaveBeenCalledWith('2026-11-09', 'fi-nl', 'partner')

    // Each leg gets its own column with its own options.
    const columns = wrapper.findAll('[data-testid="leg-column"]')
    expect(columns).toHaveLength(2)
    expect(columns[0].text()).toContain('Outbound')
    expect(columns[0].text()).toContain('Finnair')
    expect(columns[1].text()).toContain('Return')
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

describe('App — total', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('default is the cheapest outbound + cheapest return', async () => {
    // Per direction its own result, with an unranked option that is cheaper
    // but must not be chosen as default.
    vi.mocked(search).mockImplementation(async (_date, direction) =>
      direction === 'nl-fi'
        ? makeResult([makeOption('KLM', 15650), makeOption('SAS', 17000)])
        : makeResult([makeOption('Cheap', 100, { trainCostKnown: false }), makeOption('Finnair', 9875)]),
    )

    const wrapper = await searchWith('2026-10-29', '2026-11-09')

    expect(wrapper.find('[data-testid="total-sum"]').text()).toBe('€ 255,25')
    expect(wrapper.find('[data-testid="total-bar"]').text()).toContain('Cheapest combination')
  })

  it('clicking another option updates the total without a new search', async () => {
    vi.mocked(search).mockImplementation(async (_date, direction) =>
      direction === 'nl-fi'
        ? makeResult([makeOption('KLM', 15650), makeOption('SAS', 17000)])
        : makeResult([makeOption('Finnair', 9875)]),
    )

    const wrapper = await searchWith('2026-10-29', '2026-11-09')
    expect(vi.mocked(search)).toHaveBeenCalledTimes(2)

    // Second card from the outbound column: SAS € 170.00.
    const outColumn = wrapper.findAll('[data-testid="leg-column"]')[0]
    await outColumn.findAll('article')[1].trigger('click')

    const bar = wrapper.find('[data-testid="total-bar"]')
    expect(wrapper.find('[data-testid="total-sum"]').text()).toBe('€ 268,75')
    expect(bar.text()).toContain('SAS')
    // No longer the cheapest — the bar should not claim that either.
    expect(bar.text()).toContain('Your choice')
    expect(bar.text()).not.toContain('Cheapest combination')
    expect(vi.mocked(search)).toHaveBeenCalledTimes(2)

    // Back to the cheapest: the bar says so again.
    await outColumn.findAll('article')[0].trigger('click')
    expect(bar.text()).toContain('Cheapest combination')
  })
})

describe('App — dates', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    vi.mocked(search).mockImplementation(async () => makeResult([makeOption('KLM', 15650)]))
  })

  it('searches only once without return date', async () => {
    const wrapper = await searchWith('2026-10-29', '')

    expect(vi.mocked(search)).toHaveBeenCalledOnce()
    expect(vi.mocked(search)).toHaveBeenCalledWith('2026-10-29', 'nl-fi', 'partner')
    expect(wrapper.findAll('[data-testid="leg-column"]')).toHaveLength(1)
    expect(wrapper.find('[data-testid="total-sum"]').text()).toBe('€ 156,50')
    expect(wrapper.find('[data-testid="total-bar"]').text()).toContain('Outbound only')
  })

  it('does not search with return date before departure date and shows a message', async () => {
    const wrapper = await searchWith('2026-11-09', '2026-10-29')

    expect(vi.mocked(search)).not.toHaveBeenCalled()
    expect(wrapper.find('[data-testid="form-error"]').text()).toContain('Return date is before departure date')
    expect(wrapper.find('[data-testid="total-bar"]').exists()).toBe(false)
  })

  it('a return date filled in after the search does not show an empty return column', async () => {
    const wrapper = await searchWith('2026-10-29', '')
    await wrapper.find('[data-testid="back-date"]').setValue('2026-11-09')

    expect(wrapper.findAll('[data-testid="leg-column"]')).toHaveLength(1)
  })
})

describe('App — robust', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('an error on one side leaves the other side standing', async () => {
    vi.mocked(search).mockImplementation(async (_date, direction) => {
      if (direction === 'fi-nl') {
        throw new Error('ignav returned 503')
      }
      return makeResult([makeOption('KLM', 15650)])
    })

    const wrapper = await searchWith('2026-10-29', '2026-11-09')
    const [outColumn, backColumn] = wrapper.findAll('[data-testid="leg-column"]')

    expect(outColumn.text()).toContain('KLM')
    expect(backColumn.text()).toContain('ignav returned 503')
    expect(wrapper.find('[data-testid="total-sum"]').text()).toBe('€ 156,50')
    expect(wrapper.find('[data-testid="total-bar"]').text()).toContain('Return missing')
  })

  it('the search button is disabled while one side is still loading', async () => {
    // This is what prevents a race between two search actions (owner decision
    // slice 5: no requestId). Without :disabled a second search could start
    // while the first is still running.
    let releaseBack!: (r: SearchResult) => void
    vi.mocked(search).mockImplementation((_date, direction) =>
      direction === 'fi-nl'
        ? new Promise(resolve => { releaseBack = resolve })
        : Promise.resolve(makeResult([makeOption('KLM', 15650)])),
    )

    const wrapper = await searchWith('2026-10-29', '2026-11-09')
    const button = wrapper.find('button[type="submit"]')

    // Outbound is in, return not yet: button stays disabled.
    expect(wrapper.find('[data-testid="leg-column"]').text()).toContain('KLM')
    expect(button.attributes('disabled')).toBeDefined()
    expect(button.text()).toBe('Searching…')

    releaseBack(makeResult([makeOption('Finnair', 9875)]))
    await flushPromises()

    expect(button.attributes('disabled')).toBeUndefined()
    expect(button.text()).toBe('Search')
  })
})

describe('App — start country and planned trips', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    vi.mocked(search).mockImplementation(async () => makeResult([makeOption('KLM', 15650)]))
  })

  it('starting in Finland reverses both directions', async () => {
    const wrapper = mount(App)
    await flushPromises()
    await wrapper.find('[data-testid="start"]').setValue('fi')

    expect(wrapper.find('form').text()).toContain('Outbound (FI → NL)')
    expect(wrapper.find('form').text()).toContain('Return (NL → FI)')

    await wrapper.find('[data-testid="out-date"]').setValue('2026-10-25')
    await wrapper.find('[data-testid="back-date"]').setValue('2026-10-29')
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(vi.mocked(search)).toHaveBeenCalledWith('2026-10-25', 'fi-nl', 'partner')
    expect(vi.mocked(search)).toHaveBeenCalledWith('2026-10-29', 'nl-fi', 'partner')
  })

  it('a planned trip fills the date at the correct leg, given the start country', async () => {
    vi.mocked(plannedTrips).mockResolvedValueOnce([
      { date: '2026-10-25', direction: 'fi-nl', traveller: 'chris' },
      { date: '2026-10-29', direction: 'nl-fi', traveller: 'chris' },
    ])

    const wrapper = mount(App)
    await flushPromises()
    await wrapper.find('[data-testid="start"]').setValue('fi')

    const [toNl, toFi] = wrapper.findAll('.trips button')
    await toNl.trigger('click')
    await toFi.trigger('click')

    // Start in Finland: fi-nl is the outbound, nl-fi is the return.
    expect((wrapper.find('[data-testid="out-date"]').element as HTMLInputElement).value).toBe('2026-10-25')
    expect((wrapper.find('[data-testid="back-date"]').element as HTMLInputElement).value).toBe('2026-10-29')
    expect((wrapper.find('select[data-testid="traveller"]').element as HTMLSelectElement).value).toBe('chris')
  })
})
