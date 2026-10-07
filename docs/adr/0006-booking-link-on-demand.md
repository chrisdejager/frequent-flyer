# ADR-0006: Boekingslink op aanvraag, alleen bij de maatschappij zelf

**Status:** Accepted
**Date:** 2026-10-03
**Deciders:** eigenaar

## Context
De eigenaar wilde per vlucht een knop die direct naar het boekingsscherm van de
maatschappij gaat. ignav levert die links via `POST /api/fares/booking-links`
met het `ignav_id` uit de zoekrespons, maar elke aanvraag is een call van het
beperkte tegoed (1000 per maand, zie ADR-0003). Een zoekopdracht toont zes tot
acht opties; alle links vooraf ophalen zou het verbruik verviervoudigen.

Gemeten op 2026-10-03:
- Met een `market` erbij geeft booking-links een 400
  (`conflicting_booking_lookup_mode`). Het request is dus alleen `{ignav_id}`.
- De respons bevat per optie links met een `provider_type`: `airline` (de
  maatschappij zelf) of `third_party` (een reisbureau).
- Finnair en KLM geven een echte deeplink met datum en vluchtnummer.
- **SAS** geeft als `airline` alleen `http://www.flysas.com/nl/nl/` zonder prijs.
  Daarnaast staat de **KLM-codeshare** erbij, met hetzelfde toestel en dezelfde
  vlucht, maar tegen ongeveer het dubbele tarief: € 374 tegen € 193 bij SAS zelf.
- Een onbekend `ignav_id` geeft een HTTP 400. Een ignav_id uit een zoekopdracht
  van een paar uur eerder werkte nog.

## Decision
1. **Pas ophalen bij een klik.** De knop "Boeken ↗" opent `boeken.html` in een
   nieuw tabblad (`noopener`). Die pagina vraagt `GET /api/booking-link?ref=…`,
   en de server vraagt het op bij ignav.
2. **Alleen de maatschappij zelf.** Direct betekent `provider_type === "airline"`
   en https; bij meerdere links wint de goedkoopste. Reisbureaus gebruiken we
   nooit. Is er geen directe link, dan toont het tabblad dat, met de homepage van
   de maatschappij.
3. **SAS bouwen we zelf.** Bij `carrier === 'SK'` opent de knop direct
   `https://www.flysas.com/nl-en/book-new/revenue/travelers?…` met route,
   lokale vertrekdatum en vluchtnummers. Dat kost geen ignav-call. Zonder
   `outFareBasis`, dat we niet kennen, toont SAS de vluchtlijst van die dag.
4. **Cache op de server, 30 minuten.** Sleutel `ignav.booking.<ref>` in
   `cache.app`. Ook "geen directe link" wordt gecachet, storingen nooit.
5. **Storing ≠ geen link.** Elke onbruikbare respons wordt
   `BookingLinkUnavailable` en dus een 502. Het gaat om niet-2xx,
   transportfouten, kapotte JSON en ontbrekende `booking_options`. Een ref die
   niet `^[0-9a-f]{32}\z` is, geeft een 400 en bereikt ignav nooit.

## Options Considered

### Option A: op aanvraag, server-side cache (gekozen)
| Dimension | Assessment |
|-----------|------------|
| Kosten | 1 call per eerste klik op een vlucht; herhaalklikken gratis |
| Snelheid | ± 5 s bij de eerste klik, daarna direct |
| Complexiteit | Een tweede Vite-entry en één endpoint |

### Option B: alle links meteen bij het zoeken ophalen
**Pros:** de knop werkt direct, en de prijs per aanbieder is vooraf bekend.
**Cons:** 6–8 extra calls per zoekopdracht, terwijl je er meestal hooguit één
gebruikt. Afgewezen door de eigenaar.

### Option C: ook reisbureaus toestaan
**Pros:** vaker een link, soms goedkoper.
**Cons:** de eigenaar wil "altijd bij de officiële maatschappij boeken". Afgewezen.

## Trade-off Analysis
De tabbladpagina is kale TypeScript in plaats van Vue: klein en snel, maar wel
een tweede stijl in de codebase. De logica zit in `runBookingPage(deps)` met
geïnjecteerde afhankelijkheden, zodat hij zonder DOM te testen is.

Het SAS-besluit wijkt af van punt 2. Volgens die regel zou SAS altijd de
KLM-codeshare krijgen: een officiële link, maar met de prijs van KLM. Omdat de
eigenaar nooit de codeshare-prijs wil, gaat SAS buiten ignav om.

## Consequences
- Eén ignav-call per eerste klik per vlucht per halfuur.
- `bookingRef` (het `ignav_id`) reist mee in elke optie van `/api/search`. Een
  optie zonder ref krijgt geen knop, behalve bij SAS.
- Bij een gemengde vlucht kan de airline-link van de ándere maatschappij zijn.
  Dat is nog steeds officieel, en dus acceptabel.
- **Open:** ignav geeft bij SAS-vluchten de KLM-codeshareprijs als zoekprijs.
  Besluit van de eigenaar: alle SK-opties worden "prijs onbekend". Dat staat
  los van dit ADR en volgt als eigen feature.
- Herzien als SAS weer eigen prijzen en deeplinks doorgeeft (af en toe nameten),
  of als ignav de quotumtelling voor booking-links wijzigt.

## Action Items
1. [x] Gebouwd in zes slices volgens `docs/plans/booking-link/`.
2. [ ] SAS-prijs = onbekend (eigen feature).
