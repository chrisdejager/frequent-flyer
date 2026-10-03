import { describe, expect, it } from 'vitest'
import { bookingPageUrl } from '../booking'
import { makeOption } from './fixtures'

describe('bookingPageUrl', () => {
  it('geeft maatschappij, vluchtnummers en vertrekdatum mee aan de boekingspagina', () => {
    const option = { ...makeOption('Finnair', 13900), carrier: 'AY', flightNumbers: ['AY1302'] }

    const url = new URL(bookingPageUrl(option)!, 'http://x')

    expect(url.pathname).toBe('/boeken.html')
    expect(url.searchParams.get('ref')).toBe('0ea5aa41342f00000056346ef96d746d')
    expect(url.searchParams.get('carrier')).toBe('AY')
    expect(url.searchParams.get('flight')).toBe('AY1302')
    expect(url.searchParams.get('date')).toBe('2026-10-29')
  })

  it('zet meerdere vluchtnummers achter elkaar', () => {
    const option = { ...makeOption('KLM', 15500), carrier: 'KL', flightNumbers: ['KL1223', 'SK722'] }

    const url = new URL(bookingPageUrl(option)!, 'http://x')

    expect(url.searchParams.get('flight')).toBe('KL1223 · SK722')
  })

  it('neemt de vertrekdatum in de tijdzone van de vertrekluchthaven, niet in UTC', () => {
    // 22:30 UTC op 28 okt is 01:30 op 29 okt in Helsinki.
    const option = { ...makeOption('Finnair', 13900), origin: 'HEL', departAt: '2026-10-28T22:30:00+00:00' }

    const url = new URL(bookingPageUrl(option)!, 'http://x')

    expect(url.searchParams.get('date')).toBe('2026-10-29')
  })

  it('geeft null zonder bookingRef: dan valt er niets op te vragen', () => {
    expect(bookingPageUrl({ ...makeOption('Finnair', 13900), bookingRef: null })).toBeNull()
  })
})
