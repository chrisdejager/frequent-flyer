/** Hele centen → '€ 156,50', dezelfde notatie als de backend. */
export function euro(cents: number): string {
  return `€ ${(cents / 100).toFixed(2).replace('.', ',')}`
}
