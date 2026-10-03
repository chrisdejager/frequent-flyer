# Status: Boeken-knop per vlucht

- Gate 1 — Product: APPROVED 2026-10-03
- Gate 2 — Architecture: APPROVED 2026-10-03
- Gate 3 — Program Design: APPROVED 2026-10-03
- Gate 4 — Slice plan: APPROVED 2026-10-03

## Slices
- [x] Slice 1 — tracer bullet: knop → boeken.html → stub-endpoint → finnair.com
- [x] Slice 2 — happy path: ignav_id door de keten, echte adapter kiest de airline-link
- [x] Slice 3 — geen directe link: alleen third_party/niet-https → homepage; 400-validatie
- [x] Slice 4 — fouten: 502 → foutmelding in het tabblad
- [ ] Slice 5 — cache: tweede klik kost geen ignav-call
- [ ] Slice 6 — afronden: ADR-0006, overdracht, volledige suites

## Notes for a fresh session
- Verzoek eigenaar (2026-10-02): "ik zou graag directe boeking links hebben in de
  lijst. Maar ik zag dat dat bij ignav per ophalen van link een request is. Dus
  niet bij voorbaat voor elke link doen, maar pas als je op "boeken" klikt. Dus
  dat er dan een nieuw venster opent met het boekingscherm van betreffende
  maatschappij en vlucht."
- ignav: `POST https://ignav.com/api/fares/booking-links` met `ignav_id` uit de
  zoekrespons (zit al in de fixture `ignav-ams-hel.json`). Respons:
  `booking_options[].links[]` met `provider_name`, `provider_type`, `price`, `url`
  — zowel maatschappij-direct als OTA's. Default `market` is "US".
  Docs: https://ignav.com/docs/booking-links — quotumtelling niet gedocumenteerd.
  **Nog geen echte respons gemeten** (ADR-0003: eerst echte respons als fixture).
- Gate 1 goedgekeurd 2026-10-03, **zonder** het prijsverschil-punt ("bij KLM nu
  € 162"): de prijs uit de boekingslink wordt niet getoond.
- Gate 3 goedgekeurd 2026-10-03. Eigenaar bevestigde homepages: finnair.com,
  klm.nl, flysas.com.
- **Meting 2026-10-03** (`metingen/`): booking-links mét `market` → 400
  (`conflicting_booking_lookup_mode`), dus request = alleen `{ignav_id}`. Gaf voor
  AY1302 2 links: Finnair `airline` € 143 (deeplink met vluchtnummer en datum) en
  lastminute.com `third_party` € 199. Docs 02/03 daarop gecorrigeerd. Kosten:
  1 zoek-call + 1 link-call.
- **Herzien 2026-10-03 (eigenaar, na meting):** "direct" = `provider_type ===
  "airline"`, punt. Geen naam-matching, geen `carrier` naar `/api/booking-link`;
  `carrier` blijft alleen in de querystring van `boeken.html` voor tekst en
  homepage. Gates 2 en 3 daarop bijgewerkt (02/03), meegenomen bij Gate 4.
- **Slice 1 af (2026-10-03).** Frontend en backend-stub parallel door twee
  haiku-agents gebouwd (wens eigenaar), tests vooraf door de hoofdsessie.
  Review vond één fout: onbekende maatschappij werd dubbel ge-escaped → test
  toegevoegd die faalde, daarna gefixt. Mutaties (`.stop` weg bij click, bij
  keydown, `noopener` weg) maken elk precies hun test rood.
  Bewijs in de echte app (`/api/search` met een fetch-stub om tegoed te sparen):
  klik "Boeken ↗" bij AY1302 → nieuw tabblad
  `/boeken.html?carrier=AY&flight=AY1302&date=2026-10-29` → binnen 3 s op
  `https://www.finnair.com/`. Frontend 62/62, PHP 134/134, build met `dist/boeken.html`.
  Nieuwe bestanden: `web/src/booking.ts`, `web/src/boeken.ts`,
  `web/src/boeken-main.ts` (entry; `boeken.ts` blijft zonder side-effects),
  `web/boeken.html`, `api/src/Infrastructure/Http/BookingLinkController.php` (stub).
  Gecommit op master (feat(booking): tracer bullet …).
- **Plan voor slice 2 (afgesproken 2026-10-03):** ignav-adapter
  `IgnavBookingLinkSource` + zijn tests → één haiku-agent (signatures + tests
  meegeven, geen letterlijke code). `ignav_id` door de keten (adapter →
  `Itinerary` → presenter → `Option.bookingRef` → querystring) zelf doen: dat
  is de naad. Bewijs in de app: 1 echte zoekopdracht (2 calls) + klik op AY, KL
  en SK (3 link-calls). Vraag de eigenaar daarvóór niet opnieuw; dit is akkoord
  zodra hij "door met slice 2" zegt.
- **Slice 2 af (2026-10-03).** `ignav_id` → `Itinerary::$bookingRef` → presenter →
  `Option.bookingRef` → `?ref=` in `boeken.html`; knop alleen mét ref.
  `IgnavBookingLinkSource` (haiku-agent tegen vooraf geschreven tests, daarna
  ingekort) kiest de goedkoopste `airline`-link met https; controller echt via de
  poort. Nog géén cache, foutafhandeling of ref-validatie (slices 3–5): een
  ignav-fout geeft nu nog een 500. PHP 144/144 (ook `--network none`),
  frontend 65/65, build groen. Mutaties: elke nieuwe test wordt rood zonder zijn
  code; alleen het weghalen van de poort-alias blijft groen — Symfony
  auto-aliast een interface met één implementatie, de alias is dus alleen expliciet.
  **Bewijs (curl door de echte app; Chrome-extensie was niet verbonden):** 1 zoek
  (2 calls), 6 opties allemaal met ref; 3 link-calls:
  AY1302 → finnair.com-deeplink met `departureFlightNumbers=AY1302`;
  KL1251 → klm.nl-deeplink met `KL1251`;
  **SK556+SK2728 → klm.nl-deeplink met KL2858+KL8528** — dezelfde vluchten
  (codeshare, 11:15 AMS via ARN, € 484), maar ignav gaf geen SAS-eigen link.
  Dat is het geaccepteerde risico uit "least confident decisions" #1.
- **SAS → KLM-link verklaard (meting 2026-10-03, vlucht 26-11-2026).** Beide
  SAS-opties (SK556+SK2728, SK1556+SK2720; volgens `operating_carrier_name`
  helemaal door SAS gevlogen) gaven twee `airline`-links: KLM-deeplink mét prijs
  en vluchten (KL-codeshare-nummers), en SAS als `http://www.flysas.com/nl/nl/?gclid=…`
  — alleen de homepage, **http** en **zonder prijs**. De adapter laat die vallen
  (geen https; geen prijs telt als duurste), dus KLM wint. Dat is gewenst: de
  KLM-link landt op de juiste vluchten. Geen uitzondering maar structureel voor SAS.
  Kosten deze meting: 2 zoek-calls + 2 link-calls (plus 1 ruwe zoek-call AMS→TKU 29-10).
- **Slice 3 af (2026-10-03).** `no_direct_link` → tabblad met "Geen directe
  boekingslink van X gevonden" + link naar de homepage (alleen bij AY/KL/SK;
  `Object.hasOwn`, anders werd `carrier=constructor` een "bekende" maatschappij
  met `href="undefined"`). Controller: 400 bij elke ref die niet `^[0-9a-f]{32}\z`
  is, ook `?ref[]=` en een afsluitende newline (`$` liet die door — test eerst
  rood, daarna `\z`); bron dan nooit aangeroepen. Tweede gemeten fixture
  `ignav-booking-links-sas.json` (SAS http-homepage zonder prijs). PHP 161/161
  (ook `--network none`), frontend 69/69, build groen. Mutaties: alle rood, op één
  na die een gat liet zien — `return` na `navigate` weghalen bleef groen; test
  aangescherpt, nu rood. Bewijs: curl op 5 ongeldige refs → 400 in de echte app.
  Tabblad zelf niet in de browser getoond (Chrome-extensie niet verbonden); een
  echte vlucht zonder airline-link kennen we niet.
- **Browserbewijs slice 2/3 (2026-10-03, Chrome).** Klik Boeken bij AY1302 → binnen
  5 s finnair.com met AMS→HEL do 29 okt, AY1302; Finnair selecteerde de vlucht en
  ging naar `passenger-details`, gaf daar "unexpected error in the booking system"
  (aan hun kant; mogelijk geautomatiseerd tabblad — eigenaar test zelf). Klik bij
  SK556 → klm.nl metasearch-landing met KL2858/KL8528; na de cookiebanner viel
  het tabblad terug naar `/search/advanced` (niet uitgezocht). Slice 3-tabblad
  getoond met fetch-stub: "Geen directe boekingslink van SAS gevonden" + flysas.com.
- **‼ Prijsprobleem SAS (eigenaar ontdekt, 2026-10-03, vlucht 26-11).** ignav geeft
  voor SAS-vluchten de prijs van de KLM-codeshare: SK556+SK2728 € 374 in de app,
  € 193 op flysas.com; SK1556+SK2720 € 515 vs € 259. Google Flights toont bij SAS
  ook "prijs niet beschikbaar" en OTA's ~€ 186. Gevolg: de app zet SAS onterecht
  achteraan — raakt de kern (goedkoopste reis), niet alleen de boekingslink.
  Workaround van de eigenaar, getest: `https://www.flysas.com/nl-en/book-new/revenue/travelers?origin=AMS&destination=TKU&outboundDate=2026-11-26&adults=1&youths=0&children=0&infants=0&outFlightNumbers=556%2C2728`
  — zónder `outFareBasis` (kennen we niet) laat SAS de vluchtnummers vallen en
  toont de vluchtlijst van die datum en route. Nog geen besluit.
- **Besluit eigenaar 2026-10-03: SAS-link zelf bouwen.** Bij `carrier === 'SK'` opent
  de knop direct `https://www.flysas.com/nl-en/book-new/revenue/travelers?origin…&
  outboundDate…&adults=1…&outFlightNumbers=556,2728` — geen ignav-call, geen
  `bookingRef` nodig (`web/src/booking.ts`, `sasBookingUrl`). Wijkt af van "direct =
  provider_type airline" (Gate 2/3); vastleggen in ADR-0006 (slice 6).
  `carrier` SK betekent altijd: alle benen SK (prioriteit KL > AY > SK in de adapter).
  Frontend 72/72, 4 mutaties rood. Browser: knop opent precies die URL; dezelfde
  URL met de hand → SAS-vluchtlijst 26 nov, SK556 € 193.
  **Prijsprobleem SAS** (te hoge ignav-prijs in de ranglijst): apart oppakken ná
  slices 4–6 (eigenaar).
- **Besluit eigenaar 2026-10-03: SAS-prijs = onbekend (bouwen ná slice 6).** "Nooit de
  codeshare-prijs, altijd bij de maatschappij zelf boeken." De ignav-zoekrespons
  maakt geen onderscheid (zelfde `status: verified`, geen veld dat het verraadt);
  alleen booking-links laat het zien en dat kost een call per optie. Daarom: álle
  SK-opties tonen met "prijs onbekend, zie flysas.com" in de niet-gerangschikte
  bak, zonder extra calls. Terugdraaien zodra SAS weer eigen prijzen doorgeeft
  (af en toe nameten). Raakt het domein: een vlucht zonder bekende prijs bestaat
  nog niet. Idee "laagste OTA + € 10" vervalt: ignav gaf bij beide SAS-opties
  géén third_party-links.
- **Slice 4 af (2026-10-03).** Elke niet-2xx, transportfout, onleesbare JSON,
  `null` of ontbrekende/niet-lijst `booking_options` → `BookingLinkUnavailable`
  (+ warning in de log) → 502 `{"error":"De boekingslink kon niet worden opgehaald."}`.
  Een léége `booking_options` blijft "geen directe link". Tabblad: foutmelding
  (ge-escaped) + homepage-link; zonder `ref` of `carrier` → "onvolledig", geen fetch.
  PHP 170/170 (ook `--network none`), frontend 77/77, build groen; 10 mutaties
  rood. **Gemeten:** onbekend ignav_id (`000…0`) → ignav **HTTP 400** (beantwoordt
  least-confident #6 voor een verzonnen id; een écht verlopen id niet gemeten).
  Bewijs: curl → 502 in de echte app, warning in de php-log. Tabblad niet in de
  browser getoond (extensie weer losgekoppeld).
