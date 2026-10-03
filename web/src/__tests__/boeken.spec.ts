import { describe, expect, it, vi } from 'vitest'
import { runBookingPage, type BookingPageDeps } from '../boeken'
import type { BookingLinkResult } from '../types'

function deps(overrides: Partial<BookingPageDeps> = {}): BookingPageDeps & { rendered: string[] } {
  const rendered: string[] = []

  return {
    rendered,
    search: '?ref=0ea5aa41342f00000056346ef96d746d&carrier=KL&flight=KL1251&date=2026-10-29',
    render: (html: string) => { rendered.push(html) },
    fetchLink: vi.fn(async (): Promise<BookingLinkResult> => ({
      status: 'found',
      url: 'https://www.klm.nl/boeken',
      provider: 'KLM',
    })),
    navigate: vi.fn(),
    ...overrides,
  }
}

describe('runBookingPage', () => {
  it('toont eerst dat de link bij de maatschappij wordt opgehaald', async () => {
    const d = deps()

    await runBookingPage(d)

    expect(d.rendered[0]).toContain('Boekingslink ophalen bij KLM')
    expect(d.rendered[0]).toContain('KL1251')
  })

  it('ontsmet waarden uit de querystring precies één keer', async () => {
    const d = deps({ search: '?carrier=<b>X</b>&flight=<i>1</i>&date=2026-10-29' })

    await runBookingPage(d)

    expect(d.rendered[0]).toContain('&lt;b&gt;X&lt;/b&gt;')
    expect(d.rendered[0]).toContain('&lt;i&gt;1&lt;/i&gt;')
    expect(d.rendered[0]).not.toContain('<b>')
    expect(d.rendered[0]).not.toContain('&amp;lt;')
  })

  it('springt door naar de gevonden link', async () => {
    const d = deps()

    await runBookingPage(d)

    expect(d.navigate).toHaveBeenCalledWith('https://www.klm.nl/boeken')
  })

  it('vraagt de link op met de ref uit de querystring', async () => {
    const d = deps()

    await runBookingPage(d)

    expect(d.fetchLink).toHaveBeenCalledWith('0ea5aa41342f00000056346ef96d746d')
  })
})
