# Slices: Book button per flight

Each slice ends working and tested, with a mutation that proves the new
tests fail without the new code. After each slice: show, check off, ask.

1. **Tracer bullet** — "Book ↗" button in `OptionCard` (`@click.stop` + `@keydown.stop`)
   opens `book.html` (second Vite entry, also in `npm run build`); that shows
   "fetching…", calls `GET /api/booking-link`, and that stub controller returns
   hard `found` + `https://www.finnair.com` → tab navigates.
   No ignav yet, no real ref. Proof: click in real app → finnair.com.
2. **Happy path, real** — `ignav_id` → `Itinerary::$bookingRef` → presenter →
   `Option.bookingRef` → query string. `IgnavBookingLinkSource` against the measured
   fixture (`measurements/…ay1302.json` → `tests/…/fixtures/`), picks
   the link with `provider_type: "airline"`; wiring +
   controller real. Button only with a `bookingRef`.
   Proof: click on AY/KL/SK in real app → deep link from that airline.
3. **No direct link** — only `third_party` links, no links or a non-https url →
   `no_direct_link` → tab with text + homepage (finnair.com, klm.nl,
   flysas.com). Plus 400 on an invalid `ref`, source never called.
4. **Errors** — HTTP error, unreadable JSON, missing `booking_options`,
   expired id → `BookingLinkUnavailable` → 502 → error message in tab.
   Missing query string → message without fetch.
5. **Cache** — second click on same flight = no ignav call (even after reload),
   `no_direct_link` also cached, errors not. Proven by real container
   (`BookingLinkSourceWiringTest`) and in app (2nd click direct, no 3 s).
6. **Wrap-up** — ADR-0006 (booking link on demand, cache on server, only
   the airline itself), `OVERDRACHT.md`/`progress.md` added, full suites +
   network-free PHP run (`--network none`).
