import { timezoneFor } from './airports'
import type { Option } from './types'

export const CARRIER_HOMEPAGES: Record<string, { label: string; url: string }> = {
  AY: { label: 'Finnair', url: 'https://www.finnair.com' },
  KL: { label: 'KLM', url: 'https://www.klm.nl' },
  SK: { label: 'SAS', url: 'https://www.flysas.com' },
}

export function bookingPageUrl(option: Option): string {
  const timeZone = timezoneFor(option.origin)
  const date = new Intl.DateTimeFormat('en-CA', {
    timeZone,
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
  }).format(new Date(option.departAt))

  const params = new URLSearchParams({
    carrier: option.carrier,
    flight: option.flightNumbers.join(' · '),
    date,
  })

  return `/boeken.html?${params}`
}
