# Architecture: Boeken-knop per vlucht

## Fit
- **`IgnavFlightSource`** gooit `ignav_id` nu weg. Die moet mee naar buiten:
  `Itinerary` krijgt een optionele bronverwijzing (`?string`, framework-vrij),
  `SearchResultPresenter` zet hem als `bookingRef` in elke optie. Geen andere
  domeinlogica verandert; zoeken kost precies evenveel ignav-calls als nu.
- **Nieuwe poort** `BookingLinkSource` in `Domain/Port`, met een ignav-adapter
  `IgnavBookingLinkSource` in `Infrastructure/Source` naast de vluchtbron.
  Gescheiden van `FlightPriceSource`, zodat de zoek-poort niet van vorm verandert.
- **Nieuwe controller** `BookingLinkController` in `Infrastructure/Http`, naast
  `SearchController`.
- **Frontend:** `OptionCard` krijgt een knop (`@click.stop`, zodat de regel niet
  geselecteerd wordt). Nieuw: een tweede kleine pagina `boeken.html` (eigen Vite-
  entry, kale TS, geen Vue nodig) die in het nieuwe tabblad draait.

### Waarom een eigen pagina in het nieuwe tabblad
De knop doet `window.open('/boeken.html?…', '_blank', 'noopener')` **synchroon**
in de klik — dan houdt geen browser het tegen. Die pagina toont "Boekingslink
ophalen…", vraagt de link op en doet `location.replace(url)`. Alternatief
(leeg tabblad openen en vanuit de lijst vullen) vereist `document.write` en een
`window.opener`-koppeling die de maatschappijsite dan ook krijgt; afgewezen.

### "Tweede klik kost niets": cache op de server
Omdat elk tabblad een verse pagina is, kan de lijstpagina de link niet aan het
nieuwe tabblad doorgeven. Daarom cachet de **backend** het antwoord per
`ignav_id` in `cache.app` (zelfde aanpak als `ApifyTrainPriceSource`). Dit is
sterker dan Gate 1 vroeg ("zolang de pagina openstaat"): het geldt ook na herladen.
- TTL succes: 30 min. "Geen directe link" ook 30 min. Fouten **niet** cachen
  (een storing moet je opnieuw kunnen proberen).

## Endpoints
- `GET /api/booking-link?ref=<ignav_id>` — vraagt (of leest
  uit cache) de boekingslinks op en geeft precies één uitkomst:
  - `200 {"status":"found","url":"https://…","provider":"KLM"}`
  - `200 {"status":"no_direct_link"}` — alleen OTA's of niets
  - `400` bij ongeldige `ref` (moet 32 hex-tekens zijn)
  - `502 {"error":"…"}` bij storing/onleesbaar antwoord van ignav
  Geen `carrier`-parameter (herzien 2026-10-03): `provider_type: "airline"` volstaat.

## Data
Geen tabellen. Alleen cache-items `ignav.booking.<ref>` in `cache.app`
(filesystem-pool, al geconfigureerd). Lezen: `getItem` → hit = geen ignav-call.

## Flow
1. `/api/search` → `IgnavFlightSource` leest nu ook `ignav_id` → `Itinerary`
   → presenter → `option.bookingRef` in de JSON.
2. Klik "Boeken" in `OptionCard` → `window.open('/boeken.html?ref=…&carrier=KL&flight=KL1251&date=2026-10-29')`.
   Zonder `bookingRef` (mag niet voorkomen, maar defensief): knop niet tonen.
3. `boeken.html` → `fetch /api/booking-link?ref=…`
   → `BookingLinkController` → `BookingLinkSource::linkFor(ref)`
   → `IgnavBookingLinkSource`: cache-hit? → anders `POST https://ignav.com/api/fares/booking-links`
   `{ignav_id}` (**zonder** `market`: ignav geeft dan 400 `conflicting_booking_lookup_mode`, gemeten 2026-10-03) → kies de link van de maatschappij zelf → cache → terug.
4. `boeken.html`: `found` → `location.replace(url)`; `no_direct_link` → tekst +
   link naar homepage van de maatschappij (vaste lijst in de frontend:
   AY finnair.com, KL klm.nl, SK flysas.com); fout → foutmelding.

### "De maatschappij zelf" herkennen
**Herzien na meting 2026-10-03 (voorstel eigenaar):** een link telt als direct als
`provider_type === "airline"`. Reisbureaus zijn `third_party`. Geen naam-matching
op `provider_name`; de server hoeft de maatschappij dus niet te kennen. De
`carrier` in de URL van `boeken.html` blijft alleen voor de tekst en de homepage.
Meerdere directe links (bijv. verschillende `fare_name`s)? Neem de goedkoopste
(de prijs gebruiken we alleen om te kiezen, we tonen hem niet).

## External
- ignav `POST https://ignav.com/api/fares/booking-links`, header `X-Api-Key`,
  env **`IGNAV_API_KEY`** (bestaat al). Geen nieuwe env-vars.
- **Eerst meten (ADR-0003):** vóór de adapter één echte respons vastleggen als
  fixture `fixtures/ignav-booking-links.json`. Kost 1 zoek-call + 1 link-call
  van het tegoed. Daarmee leggen we vast: de waarden van `provider_type`, hoe
  `provider_name` voor AY/KL/SK heet, of de `url` direct bruikbaar is, en of
  `market: "NL"` werkt. → **Gemeten: werkt níet** (zie onderaan).
- Onbekend: of booking-links-calls van het quotum afgaan, en hoe lang een
  `ignav_id` geldig blijft. Bij een verlopen id → de foutmelding uit Gate 1.
- Bestaand risico (I7): de API heeft geen auth; `/api/booking-link` is net als
  `/api/search` vrij aan te roepen en kost dan tegoed. De `ref`-validatie en de
  cache beperken dat, lossen het niet op.

## Meting 2026-10-03 (na goedkeuring, correctie)
Ruwe respons: `metingen/2026-10-03-booking-links-ay1302.json` (Finnair AY1302, AMS→HEL, 29 okt).
- **`market` mag niet** bij een `ignav_id`-lookup → 400. De request is alleen `{ignav_id}`.
- Antwoord in **3,0 s**; 1 `booking_option` met 2 links:
  - `provider_type: "airline"`, `provider_name: "Finnair"`, € 143 — een **deeplink**
    `https://www.finnair.com/nl-en/booking/flight-selection?…&departureDate=2026-10-29&departureFlightNumbers=AY1302&…`
  - `provider_type: "third_party"`, `provider_name: "lastminute.com"`, € 199.
- Geen `fare_name`. De respons bevat ook het `itinerary` met `marketing_carrier_code`.
- KLM en SAS nog niet gemeten; niet meer nodig vooraf, want we matchen alleen op `provider_type`.
