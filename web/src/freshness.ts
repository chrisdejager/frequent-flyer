import type { Option } from './types'

export interface PricesAsOf {
  /** The oldest observedAt among the options. */
  observedAt: string
  hoursOld: number
}

/**
 * When the source last fetched these prices. The oldest one counts: one stale
 * price is enough to distrust the column. null = no option says, and unknown
 * is never shown as fresh.
 */
export function pricesAsOf(options: Option[], now: Date): PricesAsOf | null {
  const known = options
    .map(o => o.observedAt)
    .filter((at): at is string => at !== null)
    .sort((a, b) => Date.parse(a) - Date.parse(b))

  if (known.length === 0) {
    return null
  }

  return {
    observedAt: known[0],
    hoursOld: Math.floor((now.getTime() - Date.parse(known[0])) / 3_600_000),
  }
}
