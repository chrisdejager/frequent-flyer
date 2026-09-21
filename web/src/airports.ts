const TIMEZONES: Record<string, string> = {
  AMS: 'Europe/Amsterdam',
  HEL: 'Europe/Helsinki',
  TKU: 'Europe/Helsinki',
  ARN: 'Europe/Stockholm',
  CPH: 'Europe/Copenhagen',
}

export function timezoneFor(airport: string): string {
  return TIMEZONES[airport] ?? 'UTC'
}
