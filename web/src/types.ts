export type Pattern = 'via_hel_train' | 'through'
export type Direction = 'nl-fi' | 'fi-nl'
export type TravellerId = 'chris' | 'partner'

export interface Cost {
  basePrice: number
  baggageCost: number
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
