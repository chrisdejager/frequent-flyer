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

  // Alles uit de querystring komt van buiten: pas bij het renderen escapen, precies één keer.
  // hasOwn: anders is "constructor" uit de querystring een "bekende" maatschappij.
  const homepage = Object.hasOwn(CARRIER_HOMEPAGES, carrier) ? CARRIER_HOMEPAGES[carrier] : undefined
  const label = homepage?.label ?? carrier
  const details = `<p class="small">${escape(flight)} · ${escape(date)}</p>`
  const onward = homepage
    ? `<p><a href="${homepage.url}">Zoek deze vlucht op de site van ${escape(homepage.label)} ↗</a></p>`
    : ''

  // Zonder ref valt er niets op te vragen; zonder carrier weten we niet waarheen.
  // Geen fetch: elke aanvraag kan een ignav-call kosten.
  if (!ref || !carrier) {
    deps.render('<p>Deze boekingslink is onvolledig. Ga terug naar de vluchtzoeker en klik opnieuw op Boeken.</p>')
    return
  }

  deps.render(`<p>Boekingslink ophalen bij ${escape(label)}…</p>${details}`)

  let result: BookingLinkResult
  try {
    result = await deps.fetchLink(ref)
  } catch (error) {
    const message = error instanceof Error ? error.message : 'De boekingslink kon niet worden opgehaald.'
    deps.render(`<p>${escape(message)}</p>${details}${onward}`)
    return
  }

  if (result.status === 'found') {
    deps.navigate(result.url)
    return
  }

  deps.render(`<p>Geen directe boekingslink van ${escape(label)} gevonden.</p>${details}${onward}`)
}
