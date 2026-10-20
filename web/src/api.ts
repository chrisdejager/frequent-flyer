import type { BookingLinkResult, Direction, SearchResult, TravellerId } from './types'

export async function search(
  date: string,
  direction: Direction,
  traveller: TravellerId,
): Promise<SearchResult> {
  const params = new URLSearchParams({ date, direction, traveller })
  const response = await fetch(`/api/search?${params}`)

  if (!response.ok) {
    const body = await response.json().catch(() => ({ error: 'Unknown error' }))
    throw new Error(body.error ?? `Search failed (${response.status})`)
  }

  return response.json()
}

export async function fetchBookingLink(ref: string): Promise<BookingLinkResult> {
  const params = new URLSearchParams({ ref })
  const response = await fetch(`/api/booking-link?${params}`)

  if (!response.ok) {
    const body = await response.json().catch(() => ({ error: 'Unknown error' }))
    throw new Error(body.error ?? `Failed to fetch booking link (${response.status})`)
  }

  return response.json()
}
