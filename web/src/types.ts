export type Pattern = 'helsinki' | 'through'
export type Direction = 'nl-fi' | 'fi-nl'
export type TravellerId = 'default'
export type StartCountry = 'nl' | 'fi'

export type BookingLinkResult =
  | { status: 'found'; url: string; provider: string }
  | { status: 'no_direct_link' }

export interface Cost {
  basePrice: number
  total: number
  totalFormatted: string
}

export interface Option {
  carrier: string
  carrierLabel: string
  pattern: Pattern
  origin: string
  destination: string
  departAt: string
  arriveAt: string
  stops: number
  via: string | null
  connectionMinutes: number | null
  flightNumbers: string[]
  cost: Cost
  /** Reference to fetch the booking link; null = no Book button. */
  bookingRef: string | null
  /** When the source fetched this price (ISO); null = unknown. */
  observedAt: string | null
}

export interface SearchResult {
  traveller: { id: string; name: string }
  searchedAt: string
  options: Option[]
}

export interface LegState {
  direction: Direction
  /** 'YYYY-MM-DD'; '' = this leg is not searched. */
  date: string
  result: SearchResult | null
  error: string
  loading: boolean
  /** Index in result.options; null = nothing chosen. */
  selected: number | null
}
