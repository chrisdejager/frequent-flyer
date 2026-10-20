import { describe, expect, it } from 'vitest'
import { pricesAsOf } from '../freshness'
import { makeOption } from './fixtures'

const at = (observedAt: string | null) => ({ ...makeOption('KLM', 100), observedAt })

describe('pricesAsOf', () => {
  const now = new Date('2026-10-20T19:10:00Z')

  it('is null when no option says when its price was observed', () => {
    expect(pricesAsOf([], now)).toBeNull()
    expect(pricesAsOf([at(null), at(null)], now)).toBeNull()
  })

  it('reports the oldest observation: one stale price makes the column stale', () => {
    const asOf = pricesAsOf([at('2026-10-20T19:05:00+00:00'), at('2026-10-20T14:05:00+00:00')], now)

    expect(asOf?.observedAt).toBe('2026-10-20T14:05:00+00:00')
    expect(asOf?.hoursOld).toBe(5)
  })

  it('a fresh observation is zero hours old', () => {
    expect(pricesAsOf([at('2026-10-20T19:05:00+00:00')], now)?.hoursOld).toBe(0)
  })
})
