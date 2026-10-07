import { timezoneFor } from './airports'
import type { Option } from './types'

export const CARRIER_HOMEPAGES: Record<string, { label: string; url: string }> = {
  AY: { label: 'Finnair', url: 'https://www.finnair.com' },
  KL: { label: 'KLM', url: 'https://www.klm.nl' },
  SK: { label: 'SAS', url: 'https://www.flysas.com' },
}

/** Departure date as the carrier knows it: local to the departure airport. */
function departureDate(option: Option): string {
  return new Intl.DateTimeFormat('en-CA', {
    timeZone: timezoneFor(option.origin),
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
  }).format(new Date(option.departAt))
}

/**
 * We build SAS ourselves: ignav only gives a homepage or KLM codeshare with
 * ~double price (measured 2026-10-03). Without outFareBasis, which we don't
 * know, SAS shows the flight list for that date and route.
 * carrier 'SK' means all legs are SK: KLM and Finnair go first.
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
