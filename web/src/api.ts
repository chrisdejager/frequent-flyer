import type { Direction, SearchResult, Trip, TravellerId } from './types'

export async function search(
  date: string,
  direction: Direction,
  traveller: TravellerId,
): Promise<SearchResult> {
  const params = new URLSearchParams({ date, direction, traveller })
  const response = await fetch(`/api/search?${params}`)

  if (!response.ok) {
    const body = await response.json().catch(() => ({ error: 'Onbekende fout' }))
    throw new Error(body.error ?? `Zoeken mislukte (${response.status})`)
  }

  return response.json()
}

export async function plannedTrips(): Promise<Trip[]> {
  const response = await fetch('/api/trips')

  if (!response.ok) {
    return []
  }

  const body = await response.json()

  return body.trips
}
