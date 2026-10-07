export type Pattern = 'via_hel_train' | 'through'
export type Direction = 'nl-fi' | 'fi-nl'
export type TravellerId = 'chris' | 'partner'
export type StartCountry = 'nl' | 'fi'

export type BookingLinkResult =
  | { status: 'found'; url: string; provider: string }
  | { status: 'no_direct_link' }

export interface Cost {
  basePrice: number
  trainCost: number
  total: number
  totalFormatted: string
  indicative: boolean
  trainCostKnown: boolean
  /** False = the transfer HEL–TKU is deliberately not in this total. */
  trainCostIncluded: boolean
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
}

export interface SearchResult {
  traveller: { id: string; name: string }
  searchedAt: string
  options: Option[]
}

export interface Trip {
  date: string
  direction: Direction
  traveller: TravellerId
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
