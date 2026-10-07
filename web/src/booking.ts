import { timezoneFor } from './airports'
import type { Option } from './types'

export const CARRIER_HOMEPAGES: Record<string, { label: string; url: string }> = {
  AY: { label: 'Finnair', url: 'https://www.finnair.com' },
  KL: { label: 'KLM', url: 'https://www.klm.nl' },
  SK: { label: 'SAS', url: 'https://www.flysas.com' },
}

/** Vertrekdatum zoals de maatschappij hem kent: lokaal op de vertrekluchthaven. */
function departureDate(option: Option): string {
  return new Intl.DateTimeFormat('en-CA', {
    timeZone: timezoneFor(option.origin),
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
  }).format(new Date(option.departAt))
}

/**
 * SAS bouwen we zelf: ignav geeft daar alleen een http-homepage of de
 * KLM-codeshare met ± dubbele prijs (gemeten 2026-10-03). Zonder outFareBasis,
 * die we niet kennen, toont SAS de vluchtlijst van die datum en route.
 * carrier 'SK' betekent dat alle benen SK zijn: KLM en Finnair gaan voor.
 */
function sasBookingUrl(option: Option): string {
  const params = new URLSearchParams({
    origin: option.origin,
    destination: option.destination,
    outboundDate: departureDate(option),
    adults: '1',
    youths: '0',
    children: '0',
    infants: '0',
    outFlightNumbers: option.flightNumbers.map(n => n.replace(/^SK/, '')).join(','),
  })

  return `https://www.flysas.com/nl-en/book-new/revenue/travelers?${params}`
}

export function bookingPageUrl(option: Option): string | null {
  if (option.carrier === 'SK') {
    return sasBookingUrl(option)
  }

  if (!option.bookingRef) {
    return null
  }

  const params = new URLSearchParams({
    ref: option.bookingRef,
    carrier: option.carrier,
    flight: option.flightNumbers.join(' · '),
    date: departureDate(option),
  })

  return `/book.html?${params}`
}
