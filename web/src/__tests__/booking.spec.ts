import { describe, expect, it } from 'vitest'
import { bookingPageUrl } from '../booking'
import { makeOption } from './fixtures'

describe('bookingPageUrl', () => {
  it('geeft maatschappij, vluchtnummers en vertrekdatum mee aan de boekingspagina', () => {
    const option = { ...makeOption('Finnair', 13900), carrier: 'AY', flightNumbers: ['AY1302'] }

    const url = new URL(bookingPageUrl(option)!, 'http://x')

    expect(url.pathname).toBe('/book.html')
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
  describe('SAS', () => {
    // ignav geeft bij SAS alleen een http-homepage of de KLM-codeshare (± dubbele
    // prijs, gemeten 2026-10-03). We bouwen de flysas.com-link daarom zelf.
    const sas = {
      ...makeOption('SAS', 37400),
      carrier: 'SK',
      origin: 'AMS',
      destination: 'TKU',
      departAt: '2026-11-26T10:15:00+00:00',
      flightNumbers: ['SK556', 'SK2728'],
    }

    it('gaat rechtstreeks naar de vluchtkeuze op flysas.com', () => {
      const url = new URL(bookingPageUrl(sas)!)

      expect(url.origin + url.pathname).toBe('https://www.flysas.com/nl-en/book-new/revenue/travelers')
      expect(url.searchParams.get('origin')).toBe('AMS')
      expect(url.searchParams.get('destination')).toBe('TKU')
      expect(url.searchParams.get('outboundDate')).toBe('2026-11-26')
      expect(url.searchParams.get('adults')).toBe('1')
      expect(url.searchParams.get('outFlightNumbers')).toBe('556,2728')
    })

    it('heeft geen bookingRef nodig', () => {
      expect(bookingPageUrl({ ...sas, bookingRef: null })).toMatch(/^https:\/\/www\.flysas\.com\//)
    })

    it('neemt de vertrekdatum in de tijdzone van de vertrekluchthaven', () => {
      // 23:30 UTC op 25 nov is 01:30 op 26 nov in Turku.
      const url = new URL(bookingPageUrl({ ...sas, origin: 'TKU', destination: 'AMS', departAt: '2026-11-25T23:30:00+00:00' })!)

      expect(url.searchParams.get('outboundDate')).toBe('2026-11-26')
    })
  })
})
