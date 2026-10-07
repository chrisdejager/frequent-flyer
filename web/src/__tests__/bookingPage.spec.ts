import { describe, expect, it, vi } from 'vitest'
import { runBookingPage, type BookingPageDeps } from '../bookingPage'
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
  it('first shows that the link is being fetched from the carrier', async () => {
    const d = deps()

    await runBookingPage(d)

    expect(d.rendered[0]).toContain('Fetching booking link from KLM')
    expect(d.rendered[0]).toContain('KL1251')
  })

  it('sanitizes values from the query string exactly once', async () => {
    const d = deps({ search: '?ref=0ea5aa41342f00000056346ef96d746d&carrier=<b>X</b>&flight=<i>1</i>&date=2026-10-29' })

    await runBookingPage(d)

    expect(d.rendered[0]).toContain('&lt;b&gt;X&lt;/b&gt;')
    expect(d.rendered[0]).toContain('&lt;i&gt;1&lt;/i&gt;')
    expect(d.rendered[0]).not.toContain('<b>')
    expect(d.rendered[0]).not.toContain('&amp;lt;')
  })

  it('navigates to the found link', async () => {
    const d = deps()

    await runBookingPage(d)

    expect(d.navigate).toHaveBeenCalledWith('https://www.klm.nl/boeken')
    // While navigating, "no link" must not suddenly appear.
    expect(d.rendered.join()).not.toContain('No direct booking link')
  })

  it('requests the link with the ref from the query string', async () => {
    const d = deps()

    await runBookingPage(d)

    expect(d.fetchLink).toHaveBeenCalledWith('0ea5aa41342f00000056346ef96d746d')
  })
  describe('without direct link', () => {
    const noDirect = async (): Promise<BookingLinkResult> => ({ status: 'no_direct_link' })

    it('explains and links to the carrier homepage', async () => {
      const d = deps({ fetchLink: vi.fn(noDirect) })

      await runBookingPage(d)

      const last = d.rendered.at(-1)!
      expect(last).toContain('No direct booking link')
      expect(last).toContain('KLM')
      expect(last).toContain('KL1251')
      expect(last).toContain('href="https://www.klm.nl"')
      expect(d.navigate).not.toHaveBeenCalled()
    })

    it('follows the carrier from the query string', async () => {
      const d = deps({
        search: '?ref=0ea5aa41342f00000056346ef96d746d&carrier=SK&flight=SK556&date=2026-11-26',
        fetchLink: vi.fn(noDirect),
      })

      await runBookingPage(d)

      expect(d.rendered.at(-1)).toContain('href="https://www.flysas.com"')
    })

    it('does not show a broken link for an unknown carrier', async () => {
      const d = deps({
        search: '?ref=0ea5aa41342f00000056346ef96d746d&carrier=XX&flight=XX1&date=2026-11-26',
        fetchLink: vi.fn(noDirect),
      })

      await runBookingPage(d)

      const last = d.rendered.at(-1)!
      expect(last).toContain('No direct booking link')
      expect(last).not.toContain('href=')
      expect(last).not.toContain('undefined')
    })

    it('does not treat carrier as constructor as a known carrier', async () => {
      const d = deps({
        search: '?ref=0ea5aa41342f00000056346ef96d746d&carrier=constructor&flight=X&date=2026-11-26',
        fetchLink: vi.fn(noDirect),
      })

      await runBookingPage(d)

      expect(d.rendered.at(-1)).not.toContain('href=')
    })
  })
  describe('errors', () => {
    it('shows an error message if fetching fails, and does not navigate anywhere', async () => {
      const d = deps({ fetchLink: vi.fn(async () => { throw new Error('Could not fetch the booking link.') }) })

      await runBookingPage(d)

      const last = d.rendered.at(-1)!
      expect(last).toContain('Could not fetch the booking link.')
      expect(last).toContain('href="https://www.klm.nl"')
      expect(d.navigate).not.toHaveBeenCalled()
    })

    it('sanitizes the error message', async () => {
      const d = deps({ fetchLink: vi.fn(async () => { throw new Error('<img src=x onerror=alert(1)>') }) })

      await runBookingPage(d)

      expect(d.rendered.at(-1)).not.toContain('<img')
    })

    it.each([
      ['without ref', '?carrier=KL&flight=KL1251&date=2026-10-29'],
      ['with empty ref', '?ref=&carrier=KL&flight=KL1251&date=2026-10-29'],
      ['without carrier', '?ref=0ea5aa41342f00000056346ef96d746d&flight=KL1251&date=2026-10-29'],
    ])('does not request %s and reports that the link is incomplete', async (_, search) => {
      const d = deps({ search })

      await runBookingPage(d)

      expect(d.fetchLink).not.toHaveBeenCalled()
      expect(d.rendered.at(-1)).toContain('This booking link is incomplete')
    })
  })
})
