import { afterEach, describe, expect, it, vi } from 'vitest'
import { search } from '../api'

afterEach(() => {
  vi.unstubAllGlobals()
})

describe('search', () => {
  it('turns the server error message into the thrown error, so the column shows the cause', async () => {
    const message = 'Flight search is not configured: IGNAV_API_KEY is empty.'
    vi.stubGlobal('fetch', vi.fn(async () => new Response(JSON.stringify({ error: message }), { status: 503 })))

    await expect(search('2026-10-29', 'nl-fi', 'partner')).rejects.toThrow(message)
  })
})
