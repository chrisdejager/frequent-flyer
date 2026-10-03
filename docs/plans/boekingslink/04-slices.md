# Slices: Boeken-knop per vlucht

Elke slice eindigt werkend en getest, met een mutatie die bewijst dat de nieuwe
tests falen zonder de nieuwe code. Na elke slice: tonen, afvinken, vragen.

1. **Tracer bullet** — knop "Boeken ↗" in `OptionCard` (`@click.stop` + `@keydown.stop`)
   opent `boeken.html` (tweede Vite-entry, ook in `npm run build`); die toont
   "ophalen…", roept `GET /api/booking-link` aan, en die stub-controller geeft
   hard `found` + `https://www.finnair.com` terug → tabblad springt door.
   Nog geen ignav, geen echte ref. Bewijs: klik in de echte app → finnair.com.
2. **Happy path, echt** — `ignav_id` → `Itinerary::$bookingRef` → presenter →
   `Option.bookingRef` → querystring. `IgnavBookingLinkSource` tegen de gemeten
   fixture (`metingen/…ay1302.json` → `tests/…/fixtures/`), kiest
   de link met `provider_type: "airline"`; bedrading +
   controller echt. Knop alleen bij een `bookingRef`.
   Bewijs: klik op AY/KL/SK in de echte app → deeplink van die maatschappij.
3. **Geen directe link** — alleen `third_party`-links, geen links of een niet-https url →
   `no_direct_link` → tabblad met tekst + homepage (finnair.com, klm.nl,
   flysas.com). Plus 400 bij een ongeldige `ref`, bron nooit aangeroepen.
4. **Fouten** — HTTP-fout, onleesbare JSON, ontbrekende `booking_options`,
   verlopen id → `BookingLinkUnavailable` → 502 → foutmelding in het tabblad.
   Ontbrekende querystring → melding zonder fetch.
5. **Cache** — tweede klik op dezelfde vlucht = geen ignav-call (ook na herladen),
   `no_direct_link` ook gecachet, fouten niet. Bewezen door de echte container
   (`BookingLinkSourceWiringTest`) en in de app (2e klik direct, geen 3 s).
6. **Afronden** — ADR-0006 (boekingslink op aanvraag, cache op de server, alleen
   de maatschappij zelf), `OVERDRACHT.md`/`progress.md` bij, volledige suites +
   netwerkloze PHP-run (`--network none`).
