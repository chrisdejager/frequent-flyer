import { CARRIER_HOMEPAGES } from './booking'
import type { BookingLinkResult } from './types'

export interface BookingPageDeps {
  search: string
  render: (html: string) => void
  fetchLink: (ref: string) => Promise<BookingLinkResult>
  navigate: (url: string) => void
}

function escape(text: string): string {
  return text
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;')
}

export async function runBookingPage(deps: BookingPageDeps): Promise<void> {
  const params = new URLSearchParams(deps.search)
  const carrier = params.get('carrier') ?? ''
  const flight = params.get('flight') ?? ''
  const date = params.get('date') ?? ''
  const ref = params.get('ref')

  // Alles uit de querystring komt van buiten: pas bij het renderen escapen, precies één keer.
  // hasOwn: anders is "constructor" uit de querystring een "bekende" maatschappij.
  const homepage = Object.hasOwn(CARRIER_HOMEPAGES, carrier) ? CARRIER_HOMEPAGES[carrier] : undefined
  const label = homepage?.label ?? carrier

  deps.render(`<p>Boekingslink ophalen bij ${escape(label)}…</p><p class="small">${escape(flight)} · ${escape(date)}</p>`)

  const result = await deps.fetchLink(ref ?? '')

  if (result.status === 'found') {
    deps.navigate(result.url)
    return
  }

  const onward = homepage
    ? `<p><a href="${homepage.url}">Zoek deze vlucht op de site van ${escape(homepage.label)} ↗</a></p>`
    : ''

  deps.render(
    `<p>Geen directe boekingslink van ${escape(label)} gevonden.</p>`
    + `<p class="small">${escape(flight)} · ${escape(date)}</p>`
    + onward,
  )
}
