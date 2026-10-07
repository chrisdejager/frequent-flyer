# Overdracht — vluchtzoeker

**Bijgewerkt:** 3 okt 2026 · **branch:** `main` · alles groen: **PHP 176/176
(ook `--network none`), frontend 77/77, build groen.**

## 00. Stand 3 okt 2026 — boekingslink af, SAS-prijs geparkeerd

**Boeken-knop per vlucht is af** (6 slices, `docs/plans/booking-link/`, besluit in
`docs/adr/0006-booking-link-on-demand.md`). Klik → `boeken.html` → `GET
/api/booking-link?ref=<ignav_id>` → `IgnavBookingLinkSource` (alleen
`provider_type: airline` + https, goedkoopste wint, 30 min in `cache.app`) →
deeplink van de maatschappij. Storing → 502 + melding; geen directe link →
homepage. **SAS gaat buiten ignav om**: de frontend bouwt zelf een flysas.com-link.
Volledig verloop en metingen: `docs/plans/booking-link/00-status.md`.

**SAS-prijs: geparkeerd (besluit eigenaar).** ignav geeft bij SAS-vluchten de
KLM-codeshareprijs als zoekprijs (± dubbel: € 374 in de app, € 193 op flysas.com,
26-11-2026). Eigenaar acht het tijdelijk; de SAS-knop opent een zelfgebouwde
flysas.com-link om handmatig te checken. Plan + overwegingen:
`docs/plans/sas-price-unknown/00-status.md`. Af en toe nameten; blijft het, dan
misschien een andere prijsbron. (ADR-0006 noemt "prijs onbekend" nog als open
actiepunt — dat is hiermee geparkeerd.)

**Kleine waarnemingen, niet uitgezocht:**
- Finnair-deeplink kwam in het geautomatiseerde Chrome tot `passenger-details`
  en gaf daar een systeemfout; KLM-deeplink viel na de cookiebanner terug naar
  `/search/advanced`. Beide aan hun kant, niet aan de link.
- AY1306+AY229 (€ 270, overstap in HEL) ontbrak terecht: `AllowedConnectionAirports`
  staat alleen ARN/CPH toe. Eigenaar: nooit overstappen in Helsinki — van/naar HEL
  liever met de trein.

**Werkwijze die hier werkte:** tests vooraf door de hoofdsessie, eenvoudige
adapter door een haiku-agent; elke slice een mutatieronde (één bleef groen en
liet een echt gat zien: `return` na `navigate`). Twee bugs gevonden doordat
ik de grens zelf bestookte: `$` in een regex laat een newline door (→ `\z`);
`CARRIER_HOMEPAGES['constructor']` gaf `href="undefined"` (→ `Object.hasOwn`).

---

*Hieronder: de stand van 17 sep 2026 (fase 1), als geschiedenis bewaard.*

## 0a. De app draait echt (17 sep 2026, handmatig getoetst door de eigenaar)

Met een echte `IGNAV_API_KEY` geeft `/api/search` een zinnige lijst: herkenbare
maatschappijen, plausibele bedragen. **Dit is het eerste bewijs buiten de tests
om dat de keten ignav → adapter → domein → UI klopt.**

Twee dingen die daarbij bovenkwamen:

- **KLM toont "indicatief" — dat hoort zo.** `FareUpgradeRates::ESTIMATED` bevat
  alleen KL, omdat KLM ruimbagage dynamisch beprijst. Geen bug; niet "wegpoetsen".
- **De treinprijs werd opgehaald terwijl de transfer uitstond.** `TRAIN_COST_ENABLED`
  zat alleen in `TotalCostCalculator`, maar `SearchFlightsHandler` deed het ophalen.
  Elke zoekopdracht met een HEL-optie startte dus een Apify-actor-run waarvan de
  uitkomst direct werd weggegooid — seconden wachttijd en één van de ~20–50 runs
  per maand. **Achtste naad, zelfde vorm: de schakelaar in de ene component, de
  kosten in de andere.** Opgelost in `44453cc` met
  `TotalCostCalculator::usesTrainCost()`; de handler vraagt het daar op, zodat de
  schakelaar op één plek blijft wonen.

  De tests toetsen **het aantal aanroepen van de treinbron** (`FixedTrainPriceSource::$callCount`),
  in beide standen, door de echte container. Op het eindbedrag toetsen kan hier
  niet: een weggegooide uitkomst is aan het resultaat niet te zien.

**Geheimen: de key hoort in `api/.env.local`, niet in `api/.env`.** `api/.env` is
door git gevolgd (Symfony-standaard: alleen lege sleutels en veilige defaults).
`.env.local` staat in beide `.gitignore`-bestanden en wordt door Symfony over
`.env` heen gelezen — bewezen: `bootEnv()` in de container laadt de key in
`APP_ENV=dev`. In `APP_ENV=test` wordt `.env.local` **niet** geladen; dat is
precies goed, want geen enkele test mag het netwerk op.

**Nog open:** de twee ignav-calls (AMS→HEL en AMS→TKU) gaan na elkaar, elk een
seconde of wat. De eigenaar heeft parallelliseren bewust **later** geparkeerd —
het verandert de vorm van de `FlightPriceSource`-port.

## 0. Sessie 2: Bright Data is eruit, ignav erin

**Bright Data had geen Google Flights-product.** De adapter haalde via Web
Unlocker de HTML-pagina van Google Flights op maar parste `best_flights` /
`other_flights` — het JSON-schema van **SerpApi**. Die sleutels komen daar nooit
uit; die adapter heeft dus nooit tegen de echte API gewerkt. Zijn 33 tests
voerden het verzonnen schema in en zagen het gat daarom niet. **Zevende Critical,
weer in een naad: adapter versus de API waar hij tegenaan praat.**

Vervangen door **ignav** (`POST https://ignav.com/api/fares/one-way`, header
`X-Api-Key`, env `IGNAV_API_KEY`). 1000 gratis requests/maand, alleen geslaagde
calls tellen. Het plan staat in
`docs/plans/ignav-flightsource/plan.md`; alle zes taken zijn af.

De fixture `api/tests/Infrastructure/Source/fixtures/ignav-ams-hel.json` is een
**écht gemeten respons**, geen aanname. Daaruit bleek: `price.amount` is in hele
euro's (niet centen), en er komen maatschappijen (BT, TK) en overstapluchthavens
(RIX, IST) binnen buiten de toegestane set.

**De transfer HEL–TKU staat uit** via `TRAIN_COST_ENABLED=0` (eigenaarsbesluit —
hij wil er misschien een andere bron voor). Gevolg: treinkosten €0, opties blijven
gewoon gerangschikt, en elke via-Helsinki-kaart meldt "niet meegerekend" plus
"Totaal is exclusief transfer HEL→TKU". Daarvoor is een nieuw veld
`trainCostIncluded` toegevoegd, **los van `trainCostKnown`**: dat laatste false
zetten zou elke Helsinki-optie de ongerangschikte bak in duwen. Terugzetten is
één env-regel; beide standen zijn getoetst door de échte container.

**Wat hierdoor is opgelost:** M2 (`flight_number` als array). **Wat is
weggevallen:** M3 hoort nu bij `IgnavFlightSource` in plaats van de oude adapter.
**Wat minder dringend is:** I7 — met 1000 gratis calls is de credit-drain veel
kleiner dan bij Bright Data, maar auth ontbreekt nog steeds.

**Valkuil die me een half uur kostte:** in `SearchControllerTest` de env-variabele
in `tearDown` *wissen* in plaats van herstellen laat `%env(bool:...)%` gooien,
waarna élke volgende test stil een 500 geeft in plaats van zijn eigen resultaat.

Lees dit samen met [`flight-finder-phase-1/progress.md`](flight-finder-phase-1/progress.md); dat bevat de volledige
taakgeschiedenis en de doorgeschoven Minor-bevindingen. Dit bestand vult aan wat
alleen in de gespreksgeschiedenis stond.

---

## 1. WAAR JE MOET BEGINNEN

**Er ligt geen onafgemaakt werk meer.** Het afgebroken fixwerk is gecommit als
`a9b1066`; de rode frontendtest is gedicht in `d577668`.

**Stand van zaken, zelf nagemeten (sessie 2):**

| | Status |
|---|---|
| PHP-suite | **140 groen** |
| Frontend-suite | **12 groen** |
| C1 route-validatie | werkt — AMS→ARN wordt overgeslagen bij een zoekopdracht AMS→TKU |
| I3 `is_array` op `flights` | werkt — `'flights' => 'oops'` geeft 0 resultaten zonder crash |
| I1 multi-leg maatschappij | **mutatiebewijs geleverd**: "neem het laatste been" laat `BrightDataFlightSourceTest.php:792` falen |
| I2 sorteersemantiek frontend | gedekt en groen |
| I4 naam van de reiziger | gedekt door `App.spec.ts` (`.traveller-label`) |

De rode test had gelijk, de component was fout: de melding *"Niet gerangschikt —
treinprijs onbekend"* stond in `App.vue` **boven** de gerangschikte kaarten en
suggereerde daarmee dat die óók ongerangschikt waren. Hij staat nu tussen de
twee groepen.

**Wat nog open staat:** I6 en I7 hieronder, plus de tien Minors. I7 (publieke
`/api/search`, twee Bright Data-credits per aanroep) blokkeert de eerste deploy.
De app is nog **nooit als geheel met de hand gedraaid** — dat is de logische
volgende stap als de eigenaar "een werkend simpel ding" wil zien.

`toevoeging.txt` in de projectroot is **van de gebruiker**, niet van een agent.
Bevat notities over gratis databronnen (ignav.com). Niet weggooien.

---

## 2. De derde eindreview — het enige exemplaar van deze uitkomst

De review (opus, over 72 commits) staat nergens anders vastgelegd. Besluit was
**"nee, nog niet naar master"**. Het diffpakket staat in
`review-eindreview-ronde3.diff`.

### Blokkeerde de merge (dit is wat de afgebroken agent aan het repareren was)

1. **C1 (Critical) — niets controleerde of een optie de gevráágde reis verbindt.**
   Zelf gemeten vóór de fix:
   ```
   gevraagd AMS->TKU, geleverd AMS->ARN   € 59,00  geaccepteerd, bovenaan
   gevraagd AMS->HEL, geleverd HEL->AMS            geaccepteerd als heenreis
   ```
   Een vlucht naar Stockholm werd gepresenteerd als goedkoopste reis naar Turku.
   **Nu gefixt** via `ConnectsRequestedRoute` + een controle in `toItinerary()`.
2. **I3 — `$offer['flights']` werd niet op array getoetst.** Zelfde gat een laag
   dieper dan een eerdere fix. **Nu gefixt.**
3. **I1 — de maatschappij-per-been-fix was niet volledig gedekt.** Mutatie "neem
   het láátste been" liet 136/136 groen; beide multi-leg tests hadden KLM
   toevallig op het laatste been. Een gespiegelde test (KLM op been 0) ontbrak.
   **Waarschijnlijk toegevoegd** — de testfile is gewijzigd, niet geverifieerd.
4. **I2 — de nieuwe sorteersemantiek had nul frontenddekking.** Er was geen
   `App.spec.ts`, terwijl `data-testid="unranked-notice"` speciaal was
   aangebracht voor een test die niet bestond. **Dit is de test die nu rood staat.**
5. **I4 — de UI toonde nooit vóór wie de prijzen golden.** Spec §2 zegt
   letterlijk dat één prijs nooit als geldend voor beiden getoond mag worden.
   `traveller.name` werd wél meegestuurd maar nergens gerenderd. Wissel je na een
   zoekopdracht van reiziger zonder opnieuw te zoeken, dan staan Chris' prijzen
   onder een keuzelijst die "Partner" zegt — €16 verschil op Finnair.
   **App.vue is gewijzigd**, niet geverifieerd.
6. **I5 — ±1-dagsuggestie was nooit expliciet uitgesteld.** **Gedaan**: staat nu
   als blokcitaat in de spec (§3, Datumflexibiliteit).

### Mocht ná de merge

- **I6 — `FareUpgradeRates::for()` geeft stil € 0,00 voor een niet-geconfigureerde
  maatschappij.** `BaggagePolicy::costFor(Sas, None)` → `Money::zero()`, terwijl
  SAS Light zonder status wél bagage kost. Latent: beide reizigers hebben SAS
  Silver. Maar het is exact de onzichtbare nul die spec §5 verbiedt. Voorstel:
  SAS expliciet als nultarief registreren en `for()` laten gooien bij een
  onbekende sleutel.

### Blokkeert niet de merge, wél de eerste deploy

- **I7 — `/api/search` is publiek en kost twee Bright Data-credits per aanroep.**
  Geen auth, geen rate limiting, geen datumbegrenzing. Staat sinds eindreview 1
  open en is nergens vastgelegd, ook niet in de README. Basic auth op `/api` in
  nginx is de goedkoopste afdoende maatregel.

### Minors uit ronde 3 (nog nergens anders genoteerd)

| # | Plek | Bevinding |
|---|---|---|
| M1 | `api/src/Kernel.php` | mist `declare(strict_types=1)`. `DomainPurityTest` toetst alleen `src/Domain`, dus Application/ en Infrastructure/ zijn ongedekt. Overweeg de test over heel `src/` te laten lopen. |
| M2 | `BrightDataFlightSource` ~216 | `flight_number` als array → `Array to string conversion`, vluchtnummer wordt letterlijk `"Array"`. Verzonnen data, cosmetisch. |
| M3 | `BrightDataFlightSource` ~44 | `CARRIER_PRIORITY` codeert "KLM is de duurste bagagetoeslag" hard in infrastructuur, terwijl die tarieven uit configuratie komen. Zet iemand `FARE_UPGRADE_KL=10`, dan is de "conservatieve" keuze stil niet meer conservatief. |
| M4 | `MaxConnectionDuration` / `Itinerary` | negatieve overstaptijd wordt doorgelaten (gemeten −270 min, `rejectionReason` = null). Praktisch onbereikbaar. Schuift sinds taak 4 door. |
| M5 | `SearchResult.php:12` | docblock zegt nog "goedkoopste eerst"; met twee groepen klopt dat niet meer. |
| M6 | `OptionCard.vue` | bij onbekende treinprijs staat een stellig totaal groot in beeld met "Trein: onbekend" eronder. Overweeg "vanaf € 150,00". **Bewuste eigenaarskeuze.** |
| M7 | `SearchController.php:29` | `createFromFormat('!Y-m-d')` geeft geen `false` bij een onmogelijke datum: `2026-02-31` → 3 maart, `2026-13-01` → 1 jan 2027. Rolt stil door en kost een credit. |
| M8 | `api/composer.json` | `"php": ">=8.2"` terwijl de randvoorwaarde 8.4 is. Schuift sinds taak 1 door. |
| M9 | `LoggerConfigurationTest` | leest via reflectie de private `minLevelIndex` van een Symfony-interne klasse. Werkt, maar breekt stil bij een Symfony-upgrade. |
| M10 | `web/src/api.ts` | `return response.json()` zonder validatie: impliciet `any`, dus "strict, geen any" geldt niet voor het backendcontract. |

### Wat de review expliciet heeft nagerekend en goed bevonden

- **Alle 28 prijscombinaties** door de echte calculator met de echte config
  (AY €16, KL €25): beide richtingen, beide patronen, beide reizigers, trein
  bekend en onbekend. Klopt met spec §4.
- **Sorteersemantiek** in alle vier de gevallen: gemengd, alles ongerangschikt,
  geen opties, alles gerangschikt. Doorgaande tickets hebben altijd
  `trainCostKnown = true` en kunnen dus nooit in de ongerangschikte bak vallen.
- Apify-cache onderscheidt aantoonbaar "null gecacht", "€0" en "cache miss".
- Domeinzuiverheid, geen netwerk in tests, geen `any` in `web/src`.

---

## 3. Beslissingen van de eigenaar die je moet respecteren

1. **Treinprijs onbekend → optie blijft zichtbaar, gemarkeerd als "onbekend".**
   Niet verbergen, niet laten falen. Hij weegt liever zelf.
2. **Geen schatting in de sortering.** Een eerdere ronde introduceerde
   `TRAIN_PRICE_ESTIMATE` om de rangschikking te corrigeren; die bleek factor 100
   mis en is op zijn verzoek **volledig verwijderd**. Een optie met onbekende
   treinprijs doet niet mee aan de sortering en komt achteraan. Er wordt nergens
   een bedrag verzonnen, ook niet verstopt in een optelsom — het getoonde totaal
   blijft onveranderd.
3. **Drie spec-eisen bewust naar fase 2** (vastgelegd in de spec, commit `b3da6de`
   en de ongecommitte spec-wijziging): Plus-prijs naast Light tonen; KLM alleen
   tonen als het echt goedkoper is; ±1-dagsuggestie.
   Let op het gevolg bij de KLM-filter: spec §5 verdedigt de summiere
   `indicative`-markering met het argument dát KLM al weggefilterd zou zijn bij
   een marginaal verschil. Die redenering gaat in fase 1 niet op.
4. **PHP + Vue, geen Python.** Harde randvoorwaarde.

---

## 4. Het patroon dat deze branch bepaalde — lees dit vóór je verder werkt

Drie eindreviews vonden samen **zes Critical-bevindingen. Geen enkele daarvan zat
in een losse component.** Alle zes zaten in een naad:

- adapter versus regel (patroon op de terugreis)
- domein versus bedrading (de schatting die factor 100 mis was)
- aanroeper versus mapper (niemand controleerde de route)

Elke afzonderlijke taakreview was terecht positief. Het geheel klopte niet.

**Wat daaruit volgt voor jou:**

- **Meet door de échte container, niet langs de bedrading.** Ik heb de
  sorteerfix zelf nagemeten en toch gemist dat hij factor 100 mis was — omdat ik
  in mijn probe de waarde met de hand meegaf, precies zoals de tests deden. Ik
  reproduceerde de blinde vlek in plaats van hem te vinden. Alles wat uit
  configuratie komt: toetsen via `WebTestCase` met de echte container.
- **Wantrouw een test die om een probleem heen werkt.** De integratietest voor
  de terugreis bevatte commentaar dat de HEL→AMS-vlucht anders "dubbel" zou
  binnenkomen, en loste dat op door het tweede antwoord leeg te maken. Die
  workaround hield de suite groen en bewaakte de fout in plaats van hem te melden.
- **Een fix zonder falende test is geen fix.** Meerdere "reparaties" bleken
  mutatie-bestendig: de logger-bedrading weghalen liet 131/131 groen, en "neem
  het laatste vluchtbeen" liet 136/136 groen.
- **Verifieer reviewbevindingen empirisch voor je ze aanneemt of afwijst.**
  Zeven bevindingen uit losse taakreviews bleken bij meting onjuist (o.a.
  "`usort` is onstabiel" — PHP sorteert stabiel sinds 8.0; "`Money::fromEuros`
  kapt af" — het rondt af). Alle zeven weerleggingen zijn in de drie eindreviews
  overeind gebleven. Maar: bij één ervan had ik de zoekruimte te smal genomen —
  de niet-numerieke cast ernaast bleef staan en werd later een Critical.

---

## 5. Praktische zaken

```bash
# PHP-suite
docker compose exec -T php php bin/phpunit
# Bewijs dat geen test het netwerk raakt:
docker run --rm --network none -v "$PWD/api:/app" -w /app flights-php:latest php bin/phpunit
# Frontend
docker compose run --rm node npm run test
docker compose run --rm node npm run build
```

- Config heet `phpunit.dist.xml`, werkpad in de container is `/app` (= `api/`).
- `api/config/reference.php` wordt door Symfony geregenereerd bij een cache-clear
  en duikt dan op als wijziging. `git checkout --` is dan de juiste reactie.

### Opgeruimde werkbestanden

De werkmap van fase 1 bevatte 1,9 MB aan reviewdiffs, taakbriefs en
agentrapporten. Op `flight-finder-phase-1/progress.md` en dit bestand na is dat
weggegooid. Een reviewdiff maak je opnieuw met
`git diff -U10 <base>..<head>`; alle commits staan nog in git.

De agentrapporten zijn niet regenereerbaar, maar hun inhoud is tijdens de rit
samengevat in `flight-finder-phase-1/progress.md` en in de commitberichten — die zijn met opzet
uitgebreid geschreven en leggen per reparatie uit wát er mis was en waaróm dat
erg was.
