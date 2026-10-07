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
  const ref = params.get('ref') ?? ''

  // Everything from the query string is from outside: escape only when rendering, exactly once.
  // hasOwn: otherwise "constructor" from the query string would be a "known" carrier.
  const homepage = Object.hasOwn(CARRIER_HOMEPAGES, carrier) ? CARRIER_HOMEPAGES[carrier] : undefined
  const label = homepage?.label ?? carrier
  const details = `<p class="small">${escape(flight)} · ${escape(date)}</p>`
  const onward = homepage
    ? `<p><a href="${homepage.url}">Search for this flight on ${escape(homepage.label)}'s website ↗</a></p>`
    : ''

  // Without ref, there's nothing to request; without carrier we don't know where to go.
  // No fetch: every request can cost an ignav call.
  if (!ref || !carrier) {
    deps.render('<p>This booking link is incomplete. Go back to the flight finder and click Book again.</p>')
    return
  }

  deps.render(`<p>Fetching booking link from ${escape(label)}…</p>${details}`)

  let result: BookingLinkResult
  try {
    result = await deps.fetchLink(ref)
  } catch (error) {
    const message = error instanceof Error ? error.message : 'Could not fetch the booking link.'
    deps.render(`<p>${escape(message)}</p>${details}${onward}`)
    return
  }

  if (result.status === 'found') {
    deps.navigate(result.url)
    return
  }

  deps.render(`<p>No direct booking link from ${escape(label)} found.</p>${details}${onward}`)
}
