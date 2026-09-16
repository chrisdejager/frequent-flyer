# Leiden ↔ Turku vluchtzoeker — ontwerp

**Datum:** 2026-09-16
**Status:** goedgekeurd, klaar voor implementatieplan
**Bron van eisen:** [`INTAKE.md`](../../../INTAKE.md)

---

## 1. Doel

Toon de goedkoopste reismogelijkheden tussen Leiden en Turku voor een gegeven
datum, met een **eerlijke totaalprijs** die de trein Helsinki–Turku meerekent en
de bagagekosten per reiziger toepast.

Het systeem is primair een zoekmachine, niet een monitor. Prijsbewaking en
meldingen komen er later bovenop en hergebruiken dezelfde zoeklogica.

### Wat het níét is

- Geen boekingssysteem. Het toont opties; boeken doe je zelf bij de maatschappij.
- Geen algemene vluchtzoeker. Twee routes, drie maatschappijen, twee reizigers.

---

## 2. Reizigers

Bagagekosten verschillen per persoon, dus reiziger is een eersteklas begrip.

| Reiziger | SAS EuroBonus | Finnair Plus |
|---|---|---|
| Chris | Silver | Silver |
| Partner | Silver | *geen status* |

Gevolg: bij Finnair is dezelfde vlucht voor de partner duurder dan voor Chris,
omdat zij handbagage moet bijkopen. Het systeem mag nooit één prijs tonen
alsof die voor beiden geldt.

---

## 3. Reispatronen

Er zijn precies twee manieren om de reis te maken:

**A — Via Helsinki (voorkeur: rustiger)**
Directe vlucht AMS ⇄ HEL, daarna VR-trein HEL ⇄ TKU.
Totaalprijs = vluchtprijs + treinprijs.

**B — Doorgaand ticket naar Turku**
AMS ⇄ TKU met **precies één overstap**, overstaptijd ≤ 2 uur, op **één ticket**
(zodat je beschermd bent bij een gemiste aansluiting).
Overstappunten in volgorde van voorkeur: **ARN (Stockholm) > CPH (Kopenhagen)**.
Een doorgaand ticket via **HEL** (Finnair) is technisch ook patroon B, maar valt
in de praktijk samen met patroon A en wordt daar geteld. Andere overstappunten
worden niet overwogen.

### Tijdvensters

| Richting | Regel | Reden |
|---|---|---|
| NL → FI | Aankomst HEL uiterlijk **21:00** | Moet de trein naar Turku nog halen |
| FI → NL | Vertrek HEL niet vóór **12:00** | Geen nachtelijk vertrek uit Turku |

Voorkeur: middagvlucht Finnair.

### Maatschappijen

Alleen **Finnair (AY)**, **SAS (SK)** en **KLM (KL)**.
AY en SK krijgen voorrang vanwege de statussen; KL wordt alleen getoond als het
daadwerkelijk goedkoper is.

### Datumflexibiliteit

Standaard **vaste dag**. Chris zit vast aan zorgafspraken met de co-ouder.
De partner is wél flexibel. Als ±1 dag substantieel scheelt, wordt dat getoond
als **apart gemarkeerde suggestie** — nooit vermengd met de resultaten voor de
gevraagde dag.

---

## 4. Bagage en totaalprijs

Basis is altijd **Light Economy**. Daarbovenop:

| Maatschappij | Chris | Partner |
|---|---|---|
| **Finnair** | Silver → handbagage gratis op Light | Geen status → handbagage bijkopen, óf Plus-tarief |
| **SAS** | Silver → ruimbagage gratis, handbagage níét | Idem |
| **KLM** | Geen status → bijkopen of Plus | Idem |

**SAS-eigenaardigheid:** bij SAS is ruimbagage gratis (Silver) maar handbagage
niet. De praktische oplossing die het systeem moet aanhouden: de handbagage gaat
als ruimbagage mee. Er worden dus géén bagagekosten bij SAS gerekend.

Voor Finnair en KLM toont het systeem naast de Light-prijs ook de **Plus-prijs**,
zodat je kunt zien of los handbagage bijkopen of meteen Plus nemen goedkoper is.

### Treinkosten

Routes via Helsinki krijgen de VR-prijs HEL ⇄ TKU opgeteld; doorgaande tickets
naar TKU niet. Zonder deze correctie zijn de twee reispatronen niet vergelijkbaar
en lijkt "via Helsinki" structureel te goedkoop.

Duidelijke voorkeur voor **trein (VR)** boven bus.

---

## 5. Prijsbronnen

Onderzocht op 2026-09-16. Uitgangspunt: binnen gratis drempels blijven.

| Bron | Gratis drempel | Rol |
|---|---|---|
| **Bright Data** (Google Flights) | 5.000 credits/mnd, permanent | Live vluchtprijzen |
| **Travelpayouts** (Aviasales Data) | Gratis met affiliate-account | Goedkoopste-per-dag uit cache, voor baseline-opbouw |
| **Apify** (VR-scraper) | $5/mnd credits | Treinprijzen HEL ⇄ TKU |

Verwacht verbruik: ~120–250 Bright Data-calls per maand. Ruim binnen de drempel.

Apify's $5 is krapper (~20–50 runs). Treinprijzen worden daarom **agressief
gecached** en alleen opgehaald voor daadwerkelijke reisdatums.

### Afgevallen

- **Amadeus Self-Service** — opgeheven op 17 juli 2026, keys werken niet meer.
- **Kiwi Tequila** — open aanmelding gesloten.
- **Duffel** — testmodus levert nepdata, live is pay-per-booking.
- **SerpApi** — 250 calls/mnd gratis is te krap.
- **Digitraffic** — gratis en officieel, maar bevat alleen dienstregeling, geen prijzen.

### Bekende beperking: Plus-tarieven

Google Flights levert fare-informatie als losse tekst (`"Checked baggage for a
fee"`), niet als gestructureerde Light/Plus-vergelijking. De meerprijs van Light
naar Plus komt daarom niet uit een bron, maar uit **configuratie**:

```yaml
fare_upgrade:
  AY: 16.00    # Finnair — vast tarief, betrouwbaar
  KL: 25.00    # KLM — dynamisch geprijsd, dit is een schatting
```

Het systeem moet weigeren te starten bij een ontbrekende waarde in plaats van
stilletjes €0 te rekenen — een onzichtbare nul zou Light-tarieven structureel
te gunstig laten lijken.

Het verschil in zekerheid is wezenlijk en moet zichtbaar blijven:

- **Finnair** hanteert een vast tarief voor de stap naar Plus. De waarde is
  statisch bekend en de vergelijking dus hard.
- **KLM** prijst dynamisch. De geconfigureerde waarde is een schatting. Elke
  KLM-optie waarvan de uitkomst afhangt van deze meerprijs wordt in de
  interface gemarkeerd als **indicatief**, zodat je hem niet als vaststaand leest.

Dat KLM sowieso alleen verschijnt als het duidelijk goedkoper is, dempt het
risico: bij een marginaal verschil is KLM al weggefilterd voordat de onzekere
meerprijs ertoe doet.

---

## 6. Architectuur

```
flights/
├─ compose.yaml           # php-fpm · nginx · node(dev) · scheduler
├─ compose.prod.yaml      # override voor de Ubuntu VPS
├─ api/                   # Symfony 7
│  └─ src/
│     ├─ Domain/          ← geen framework-imports, puur PHP
│     │  ├─ Flight/       Itinerary · Segment · Carrier · Airport · Money
│     │  ├─ Traveller/    Traveller · LoyaltyStatus · BaggageEntitlement
│     │  ├─ Pricing/      TotalCost · BaggagePolicy · TrainSurcharge
│     │  ├─ Rules/        TimeWindow · ConnectionRule · CarrierFilter · RuleSet
│     │  ├─ Alerting/     Baseline · PriceObservation · Trigger
│     │  └─ Port/         FlightPriceSource · TrainPriceSource · PriceHistory
│     ├─ Application/     SearchFlights · EvaluateAlerts
│     ├─ Infrastructure/  Source/ · Persistence/ · Http/
│     └─ Ui/Cli/          console commands voor de scheduler
└─ web/                   # Vue 3 + Vite + TypeScript
```

### Waarom deze indeling

De complexiteit zit in **regels, bagage en totaalprijs** — precies de laag zonder
I/O. Die blijft puur: value objects, geen database, geen HTTP. Daar liggen de
unit-tests, en die draaien in milliseconden.

Persistentie is klein: alleen `PriceObservation` en `Baseline` hoeven bewaard.
Opslag is **SQLite** — één bestand op een volume, geen databaseserver in de
compose-stack. Voor één gebruiker en een paar duizend prijswaarnemingen per jaar
is dat ruim voldoende. Doctrine-mapping gaat via XML in `Infrastructure/`, zodat
domeinklassen schoon blijven zonder dat er een tweede modellaag ontstaat.

De `Port/`-interfaces zijn geen ceremonie: er zijn drie vervangbare externe
bronnen, en de kans dat er één omvalt is reëel. Dat is precies de casus voor
dependency inversion.

### Datamodel

```
Itinerary      pattern (VIA_HEL | THROUGH) · segments[] · carrier
               departAt · arriveAt · connectionMinutes · fareFamily
Segment        from · to · departAt · arriveAt · flightNumber
TotalCost      basePrice · baggageCost · trainCost · total  (per reiziger)
Traveller      name · loyalty: map<Carrier, LoyaltyStatus>
PriceObservation  route · travelDate · carrier · cheapestPrice · observedAt
Baseline       route · carrier · normalCheapest · source (seeded | learned)
```

### Applicatiestroom

```
HTTP GET /api/search
  → SearchFlightsQuery(date, direction, traveller)
    → FlightPriceSource.search()      ┐
    → TrainPriceSource.priceFor()     ├ Infrastructure
                                      ┘
    → RuleSet.filter(itineraries)     ┐
    → BaggagePolicy.costFor(t, i)     ├ Domain, puur
    → TotalCost.compute()             ┘
    → PriceHistory.record()
  → JSON
```

---

## 7. API

| Endpoint | Doel |
|---|---|
| `GET /api/search?date=&direction=&traveller=` | Zoek opties voor één datum |
| `GET /api/trips` | Geplande reizen (fase 1: hardcoded) |
| `GET /api/observations?route=&from=&to=` | Prijsgeschiedenis (fase 2) |

`direction` is `nl-fi` of `fi-nl`. `traveller` is `chris` of `partner`.

---

## 8. Frontend

Vue 3 + Vite + TypeScript, losse SPA tegen de JSON-API.

Fase 1 is één scherm: datumkiezer, reizigerkeuze, resultatenlijst. Elk resultaat
toont de totaalprijs met de opbouw eronder uitgeklapt — vluchtprijs, bagage,
trein — zodat zichtbaar is waaróm een optie wint.

---

## 9. Meldingen (fase 3)

Resultaat wordt getoond op het **dashboard**; geen e-mail, Telegram of push.

**Baselines**, geseed uit ervaring en daarna zelflerend uit waarnemingen:

| Maatschappij | Route | Normaal goedkoopst | Zekerheid |
|---|---|---|---|
| Finnair | AMS → HEL | €134 | zeker |
| Finnair | HEL → AMS | €80 | zeker |
| SAS | AMS → TKU | ~€150 | onzeker |
| SAS | TKU → AMS | ~€90 | onzeker |

**Trigger A — kortingsperiode.** Prijs zakt onder de baseline. Dit is de
belangrijkste: kortingsacties komen onvoorspelbaar.

**Trigger B — high alert.** Prijs stijgt op een reeds geplande reisdatum. Dit
betekent dat er te lang gewacht is.

---

## 10. Docker en deploy

Lokaal: `docker compose up` start alles, inclusief Vite met hot reload.

Op de Ubuntu-VPS: dezelfde compose met productie-override. Vue wordt gebouwd tot
statische bestanden, nginx serveert die, een scheduler-container draait de
periodieke taken.

Checkfrequentie: **dagelijks** (niet expliciet gekozen in de intake; dagelijks
past binnen alle quota en is fijnmazig genoeg om kortingsacties te zien).

---

## 11. Testen

| Laag | Gereedschap | Fase |
|---|---|---|
| Domein: regels, bagage, totaalprijs | PHPUnit, in-memory | 1, test-driven |
| Bron-adapters | PHPUnit tegen opgenomen HTTP-fixtures | 1 |
| API-endpoints | Symfony `WebTestCase` | 1 |
| End-to-end | Playwright | 2 |

De opgenomen fixtures zijn essentieel: tests verbranden zo nooit Bright
Data-credits, draaien offline en zijn deterministisch.

---

## 12. Fasering

| Fase | Inhoud |
|---|---|
| **1** | Zoeken + regels + bagage + totaalprijs + dashboard. Alleen 25 en 29 oktober 2026. |
| 2 | Prijsgeschiedenis vastleggen; Playwright e2e |
| 3 | Kortings- en high-alert-meldingen op het dashboard |
| 4 | Google Calendar inlezen in plaats van handmatige reislijst |

### Geplande reizen (fase 1, hardcoded)

```
25 okt 2026   partner   FI → NL
29 okt 2026   partner   NL → FI
```

Beide geplande reizen zijn van de partner. Haar profiel (SAS Silver, Finnair
geen status) is dus meteen het relevante geval.

---

## 13. Open punten

1. ~~**Plus-tarieven.**~~ *Opgelost 2026-09-16:* statisch configureerbaar.
   Finnair is een vast, bekend tarief; KLM is dynamisch en daarom een schatting
   die als indicatief wordt gemarkeerd. Zie §5.
2. **"Tot hoe lang van tevoren is los handbagage bijkopen het goedkoopst?"**
   Uit de intake, maar geen enkele bron levert dit gestructureerd. Uitgesteld
   naar fase 3; mogelijk alleen als statische vuistregel op te nemen.
3. **SAS-baselines** (~€150 / ~€90) zijn onzeker en worden na enkele weken
   waarnemingen vervangen door geleerde waarden.
4. **Bright Data-registratie** vereist een account; of er in de praktijk toch om
   een betaalmiddel wordt gevraagd, blijkt pas bij aanmelden.
