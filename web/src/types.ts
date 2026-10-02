export type Pattern = 'via_hel_train' | 'through'
export type Direction = 'nl-fi' | 'fi-nl'
export type TravellerId = 'chris' | 'partner'

export interface Cost {
  basePrice: number
  trainCost: number
  total: number
  totalFormatted: string
  indicative: boolean
  trainCostKnown: boolean
  /** False = de transfer HEL–TKU zit bewust niet in dit totaal. */
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
  /** 'YYYY-MM-DD'; '' = deze kant wordt niet gezocht. */
  date: string
  result: SearchResult | null
  error: string
  loading: boolean
  /** Index in result.options; null = niets gekozen. */
  selected: number | null
}
