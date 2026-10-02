# Architecture: Heen en terug op één scherm

## Fit
**Alleen de frontend (`web/`) verandert. De backend blijft ongewijzigd.**

Een retour bestaat hier uit twee losse enkele reizen (zo heeft de eigenaar het
besloten in Gate 1), en `GET /api/search` doet al precies één enkele reis:
datum + richting + reiziger. De frontend roept hem daarom twee keer aan, één
keer per kant, en zet de uitkomsten naast elkaar.

- `App.vue`: het formulier krijgt een startland en twee datums. De resultaten
  komen in twee kolommen, met daarboven de totaalbalk.
- `OptionCard.vue`: wordt kiesbaar (geselecteerd of niet) en blijft verder gelijk.
- `api.ts`: `search()` blijft zoals hij is.
- Backend: `SearchController`, `SearchFlightsHandler`, de presenter en de
  regels/calculator blijven onaangeroerd.

**Afgewezen alternatief: een nieuw `/api/round-trip`-endpoint.**
- In PHP gaan de ignav-calls na elkaar. Een retour zou dan vier calls achter
  elkaar worden, tegenover twee keer twee parallel vanuit de browser.
- Het parallelliseren van de `FlightPriceSource`-port is bewust geparkeerd
  (zie OVERDRACHT).
- Het levert niets op wat de frontend niet zelf kan: het totaal is gewoon twee
  bedragen in centen bij elkaar opgeteld.

## Endpoints
Geen nieuwe. Er wordt hergebruikt:
- `GET /api/search?date=&direction=&traveller=`: eenmaal voor de heenreis, en
  eenmaal voor de terugreis als er een terugdatum is.
- `GET /api/trips`: ongewijzigd. Een geplande reis vult de heen- of de
  terugdatum in, afhankelijk van de richting ten opzichte van het gekozen
  startland.

## Data
Geen database, geen nieuwe velden. Er is alleen state in de browser:
- het startland (`nl` | `fi`), de heendatum en een optionele terugdatum
- per kant een eigen `SearchResult | null`, `error` en `loading`
- per kant de index van de gekozen optie (standaard de goedkoopste
  gerangschikte optie)

## Flow
1. De gebruiker vult in: startland, heendatum, eventueel een terugdatum, en de
   reiziger. Dan klikt hij op Zoek.
2. Eerst controleert de frontend dat de terugdatum niet vóór de heendatum ligt.
   Zo niet, dan volgt een melding en wordt er niets aangeroepen.
3. De richtingen volgen uit het startland. Bij Nederland is heen `nl-fi` en
   terug `fi-nl`; bij Finland is het omgekeerd.
4. Beide `search()`-calls worden **parallel** gestart (`Promise.allSettled`).
   Elke kant vult zijn eigen resultaat of foutmelding, onafhankelijk van de
   andere kant.
5. Per kant wordt standaard de eerste gerangschikte optie gekozen; dat is de
   goedkoopste, omdat de backend al op totaal sorteert.
6. De totaalbalk telt `cost.total` (in centen) van de gekozen optie(s) op. Is
   er een kant zonder keuze (geen opties, fout of geen terugdatum), dan telt
   alleen de andere kant mee, en dat staat er ook bij.
7. Klikt de gebruiker een andere optie aan, dan verandert de keuze van die kant
   en rekent het totaal opnieuw. Er volgt geen nieuwe API-call.

## External
- **ignav:** het aantal calls per retour is gelijk aan twee losse zoekacties
  (2 per kant). De backend blijft dezelfde `IGNAV_API_KEY` gebruiken; daar
  verandert niets aan.
- **Apify (trein):** bij `TRAIN_COST_ENABLED=1` kost elke kant met een HEL-optie
  een run per datum, net als nu bij twee losse zoekacties. Standaard staat de
  trein uit, dus 0 runs.
- **php-fpm:** er komen nu twee gelijktijdige requests binnen. De standaard
  `pm.max_children` (5) is daar ruim genoeg voor.
