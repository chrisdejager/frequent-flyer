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
  const label = CARRIER_HOMEPAGES[carrier]?.label ?? carrier

  deps.render(`<p>Boekingslink ophalen bij ${escape(label)}…</p><p class="small">${escape(flight)} · ${escape(date)}</p>`)

  const result = await deps.fetchLink(ref ?? '')

  if (result.status === 'found') {
    deps.navigate(result.url)
  }
  // andere uitkomsten worden in latere slices afgehandeld
}
