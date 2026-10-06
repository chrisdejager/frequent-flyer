# Vluchtzoeker fase 1 — voortgang

Plan: docs/superpowers/plans/2026-09-16-vluchtzoeker-fase-1.md
Branch: feature/vluchtzoeker-fase-1
Startcommit: ddcf372

## Afgerond

Taak 1: compleet (commits ddcf372..9ca79c1, review schoon)
Taak 2: compleet (commits d001a4d..2bb88bc, review schoon)
Taak 3: compleet (commits 2bb88bc..78e400a, review schoon)
Taak 4: compleet (commits 78e400a..8b6beb5, review schoon)
Taak 5: compleet (commits 8b6beb5..0d0b210, review schoon)
Taak 6: compleet (commits 0d0b210..6f8bc7b, review schoon)
Taak 7: compleet (commits 6f8bc7b..3ae8718, review + grenstests)
  - Reviewer meldde Important "readonly + default = fatale fout op PHP 8.4".
    NAGEMETEN EN ONJUIST: een default op een gepromote *parameter* mag wel;
    alleen een default op een gewone *property* is fataal. Geen fix gedaan.
  - Wel toegevoegd: grenstests voor 21:00 en 12:00 (waren ongedekt).
    Mutatietest bevestigde dat ze falen bij <= -> <.
Taak 8: compleet (commits 3ae8718..c6b8812, review + extra test)
  - Reviewer wilde een test voor "KLM zonder bagagekosten via een reiziger".
    Die staat kan niet bestaan: BaggagePolicy geeft KLM altijd (false,false),
    dus KLM rekent altijd. Wel bereikbaar via config FARE_UPGRADE_KL=0.
    Die versie is nu getest, met mutatietest als bewijs.
Taak 9: compleet (commits c6b8812..b9fec89, review schoon)
Taak 10: compleet (commits b9fec89..ba3a128, review + twee robuustheidsfixes)
  - `.gitignore` bevatte de regel `.env` zonder leidende slash; die matchte
    `api/.env` op elk niveau, waardoor de FARE_UPGRADE-variabelen stil buiten
    git bleven. Regel verwijderd, `api/.env` alsnog gecommit. Zonder die fix
    zou een verse clone bij taak 13 weigeren te starten zonder uitleg.
  - Fix 1 (ada1150): een onleesbaar tijdstip sloopte de hele zoekopdracht;
    nu vervalt alleen dat ene aanbod.
  - Fix 2 (ba3a128): de `?? ''`-fallback uit fix 1 was zelf gevaarlijk.
    `new \DateTimeImmutable('')` gooit geen exception maar geeft "nu" terug,
    dus een ontbrekende `time`-sleutel verzon een vlucht die vandaag vertrekt.
    Empirisch nagemeten in de container. Nu expliciet afgevangen vóór de
    constructor; mutatietest bevestigde het (2 reisopties zonder de guard).
  - Suite: 56 groen, zelf nagedraaid.
Taak 11: compleet (commits ba3a128..57b1c0f, review + twee fixes)
  - Reviewer gaf Spec ✅ maar keurde de kwaliteit af op één Critical:
    "floats als tussentype voor geld". NAGEMETEN EN ONJUIST: afkappen zou
    slopen ((int)(0.29*100)=28), maar Money::fromEuros gebruikt round() en
    geeft altijd het juiste bedrag. De voorgestelde fix was dezelfde
    rekensom, verplaatst. Ook min()-vóór-afronden == afronden-vóór-min,
    want round() is monotoon. Geen fix gedaan.
  - GEEN her-review gedraaid. De enige blokkerende bevinding was die
    weerlegde Critical; de resterende fixes zijn 6 regels die ik zelf heb
    nagekeken (diff + volledige suite). Bewust afgeweken van het proces.
  - Fix 1: array_filter zonder callback filterde ook prijs 0 weg. Effect
    wees de verkeerde kant op: een gratis rit verdwijnt en de treinprijs
    wordt te hoog gerapporteerd.
  - Fix 2: een leeg Apify-antwoord werd 24 uur gecacht als "trein kost €0".
    Een lege lijst betekent meestal een mislukte scrape, niet "geen trein".
    Een dag lang een gratis trein meerekenen laat route-via-Helsinki
    kunstmatig winnen. Nul wordt nu niet meer gecacht; de aanroeper krijgt
    nog steeds Money::zero(). Mutatietest bevestigd.
    LET OP: dit wijkt af van de letterlijke plan-code (stap 5 cachet
    onvoorwaardelijk). Geen plan-eis geschonden, voorgeschreven test blijft
    groen. Prijs: een kapotte Apify kost nu een draaibeurt per zoekopdracht
    in plaats van per dag. Met de gebruiker gedeeld.
  - Suite: 63 groen, zelf nagedraaid.
Taak 12: compleet (commits 57b1c0f..3b40c13, review + één test erbij)
  - Reviewer keurde af op twee gronden; BEIDE NAGEMETEN EN ONJUIST:
    * Critical "needsTrainPrice hardcoden op false haalt alle zes tests":
      uitgevoerd, 3 tests falen. Test 1 rekent de treinprijs mee in het
      verwachte totaal (15650), dus zonder trein klopt het bedrag niet.
    * Important "usort is onstabiel bij gelijke prijs": PHP sorteert sinds
      8.0 stabiel. Vijf runs op 8.4 gaven identieke volgorde.
  - De onderliggende zorg was wél terecht, maar verkeerd benoemd. Zelf de
    mutatie gezocht die er wel doorheen glipt: needsTrainPrice($candidates)
    i.p.v. ($valid) — alle zes tests bleven groen. Dat is het geval dat het
    Apify-tegoed opeet: HEL-vluchten gevonden maar allemaal weggefilterd op
    aankomsttijd. De bestaande "geen HEL-optie"-test gaf helemaal geen
    HEL-vlucht mee, dus daar zijn beide lijsten leeg en meet je niets.
  - Fix: één test toegevoegd
    (test_the_train_price_is_not_fetched_when_the_only_helsinki_option_is_filtered_out).
    Geen productiecode gewijzigd — code was correct, bewijs ontbrak.
    Geverifieerd dat git diff -- api/src/ leeg is.
  - Zelf gecontroleerd: HelsinkiTimeWindow toetst alleen patroon A, dus een
    doorgaand ticket naar TKU valt niet ten onrechte af op de treintijden.
  - Suite: 70 groen, zelf nagedraaid.
Taak 13: compleet (commits 3b40c13..706ada7, review goedgekeurd + één fix)
  - Plan-fout vooraf onderschept: de controllertest verving alleen de
    vluchtbron, niet de treinbron. De Finnair-vlucht haalt alle regels en is
    patroon A, dus de handler zou de ECHTE Apify-adapter aanroepen — een
    netwerkaanroep in een test én een draaibeurt van het maandtegoed, elke
    suite-run. Ook TrainPriceSource wordt nu vervangen; assertions op
    trainCost=2250 en total=15650 bewijzen dat het dubbel gebruikt wordt.
  - BEWIJS randvoorwaarde: volledige suite gedraaid in een container met
    --network none. 77 groen. Dat toont zowel "geen test raakt het netwerk"
    als dat de testdubbels echt aankomen (anders was de controllertest
    omgevallen).
  - FARE_UPGRADE_AY=16 / KL=25 staan nu voor het eerst in .env + de
    fromConfig-factory. De losse draad uit taak 10 is daarmee dicht.
  - Reviewbevindingen NAGEMETEN EN ONJUIST:
    * Critical "tijdzone laat de datum verspringen in PlannedTrips":
      getest op UTC, Europe/Amsterdam, Pacific/Kiritimati (+14) en
      America/Anchorage (-8). Geen enkele sprong — constructie en format()
      gebruiken dezelfde tijdzone, er is geen conversie.
    * Important "when@test-aliassen staan semantisch omgekeerd": nee, dit
      is de normale Symfony-vorm; `alias:` noemt het doel. Identiek aan de
      shorthand die elders in hetzelfde bestand staat.
  - Fix (706ada7): test_the_trips_are_sorted_by_date bewaakte niets. De twee
    reizen stonden al op volgorde, dus usort() volledig verwijderen liet
    beide tests groen. Nagemeten. Volgorde in TRIPS omgedraaid; nu falen
    beide tests zonder usort. Endpoint-uitvoer ongewijzigd.
  - Suite: 77 groen, zelf nagedraaid.
Taak 14: compleet (commits 706ada7..6bbb239, review goedgekeurd + twee fixes)
  - Plan-fout vooraf onderschept: het dashboard rendeerde vluchttijden in de
    tijdzone van de browser. Gemeten met TZ=Europe/Amsterdam: vertrek 13:00
    werd getoond als 14:00, aankomst 16:45 als 17:45. Dat is hier ernstig —
    de backend weigert een HEL-aankomst ná 21:00 omdat je dan de trein mist,
    dus een toegelaten vlucht van 20:30 zou als 21:30 op het scherm staan.
    Maatschappijen noemen lokale luchthaventijd; de backend bewaart de
    brontijd; het scherm moet dus niet omrekenen. timeZone:'UTC' op twee
    plekken (OptionCard.time en App.label) + een test die het vastlegt.
    Zelf nagespeeld: zonder de correctie faalt die test met
    "expected 'Finnair14:00 → 17:45…' to contain '13:00'".
  - Fix (f737b1e): web/tsconfig.tsbuildinfo was meegecommit. Buildcache van
    vue-tsc -b, machinegebonden. Uit git gehaald + .gitignore-regel.
  - Fix (6bbb239): de uitsplitsingstest toetste alleen labels. Nagemeten:
    euro() kon door 1000 delen (€134,00 -> €13,40) met alle 7 tests groen.
    Bedragen per regel toegevoegd + een test dat de delen optellen tot het
    totaal. Mutatie faalt nu met "expected 'Vlucht€ 13,40' to contain
    '€ 134,00'".
  - Frontend: 8 tests groen, vue-tsc build schoon. Zelf nagedraaid.
Taak 15: compleet (commits 6bbb239..375ac97, geen aparte review — zie hieronder)
  - Zuiverheidstest verbreed t.o.v. het plan: die zocht alleen op `use
    Symfony\`, waardoor een volledig gekwalificeerde verwijzing zonder
    use-regel (`new \Symfony\...`) erdoorheen glipt — precies hoe zoiets er
    over een jaar alsnog in komt. Zoekt nu op de prefix zelf.
  - Zelf nagemeten dat de test kán falen (hij is meteen groen, dus dat moest
    bewezen):
    * `use Symfony\...` in Money.php -> "Money.php bevat verwijzing naar Symfony\"
    * `\Symfony\...` zonder use-regel -> zelfde melding (de verbreding werkt)
    * strict_types weggehaald -> "ontbrekende strict_types: Money.php"
  - Domein bij afsluiten: 26 bestanden, geen framework-verwijzing, overal
    strict_types.
  - README-claim GECONTROLEERD en gecorrigeerd (375ac97). De tekst beloofde
    dat de app "niet start" zonder FARE_UPGRADE. Nagemeten via de echte
    containerweg: de app start wel, maar de eerste zoekopdracht geeft een 500
    ("Environment variable not found" bij ontbreken, "Non-numeric env var ...
    cannot be cast to float" bij leeglaten). De bescherming tegen een
    stilzwijgende EUR 0 werkt dus, maar het faalmoment ligt bij gebruik en
    niet bij deploy. Dat staat nu zo in de README.
    LET OP voor later: mijn eerste probe suggereerde dat een lege waarde stil
    EUR 0,00 opleverde. Die probe was fout — die omzeilde de %env(float:)%-
    processor door de cast met de hand te doen. Het echte pad is veilig.
  - Eindstand: PHP 80 groen, JS 8 groen, werkkopie schoon.

## Eindreview (opus, over de hele branch ddcf372..375ac97)

Verdict was **niet mergen**. Drie Critical, alle drie in de SAMENHANG tussen
taken — geen enkele losse taakreview kon ze zien. Dat is de kernles van deze
branch: per taak was alles correct, het geheel niet.

C1 — OP DE TERUGREIS VERDWEEN DE TREINPRIJS EN DE VERTREKREGEL.
  BrightDataFlightSource bepaalde het patroon alleen op de BESTEMMING. Heen
  (AMS->HEL) klopt dat; terug is HEL het VERTREKpunt, dus elke HEL->AMS-vlucht
  werd ThroughTicket. Daarmee viel alles weg wat aan patroon A hangt: geen
  treinprijs, geen 12:00-regel, geen Apify-aanroep.
  ZELF GEMETEN, voor de fix:
    patroon: through (verwacht via_hel_train)
    1 optie teruggegeven (verwacht 0 — vertrek 08:00 ligt voor 12:00)
    AY841 | basis 80,00 | bagage 16,00 | TREIN 0,00 | TOTAAL 96,00
    (moest 118,50 zijn — 19% te laag, structureel, en precies de kant op die
     patroon A onterecht laat winnen)
  NA de fix gemeten: 08:00 -> 0 opties, treinbron 0x; 14:00 -> TOTAAL 118,50.
  Waarom niemand het zag: alle 8 adaptertests zochten AMS->HEL; de enige
  FiToNl-handlertest gaf LEGE vluchtlijsten mee; de FiToNl-regeltests maken
  ViaHelsinkiWithTrain met de HAND aan — een waarde die de productiecode op
  die richting nooit aanmaakte. Ik dacht dit bij T12 gecontroleerd te hebben,
  maar ik toetste of de REGEL het patroon goed leest, niet of de ADAPTER dat
  patroon op de terugreis ooit produceert.
  Raakt de helft van de scope: 1 van de 2 geplande reizen is fi-nl.

C2 — ONLEESBARE PRIJS WERD EEN SPOTGOEDKOPE VLUCHT.
  Alleen isset(), geen is_numeric(). Gemeten: '1.234,00' -> EUR 1,23 (een
  vlucht van 1234 euro komt als goedkoopste bovenaan), '134,50' -> 134,00,
  'N/A' -> 0,00, -5 -> exception die de HELE zoekopdracht sloopte (buiten de
  bestaande try/catch om). De zoek-URL vraagt zelf om NL-notatie (hl=nl), dus
  dit was geen hypothetisch geval.
  De fixer vond hier ongevraagd iets ergers: json_decode('1e999') geeft INF,
  en (int) round(INF*100) is 0. NAGEMETEN EN JUIST. Spookvlucht van EUR 0,00
  bovenaan. Ook int-overflow gaf een TypeError i.p.v. InvalidArgumentException.
  Nu: alle onbruikbare prijzen slaan dat ENE aanbod over, met logregel.

C3 — "GEEN TREINPRIJS" WAS NIET TE ONDERSCHEIDEN VAN "TREIN KOST NUL".
  Dit was mijn eigen openstaande punt uit T11, doorgeschoven naar T12 en daar
  NOOIT BESLIST. De nul liep ongewijzigd door tot in het totaal. 6 van de 7
  storingsvormen van Apify gaven een gratis trein.
  BESLISSING VAN DE GEBRUIKER (gevraagd, niet zelf ingevuld): opties blijven
  zichtbaar, trein wordt als "onbekend" gemarkeerd. Hij weegt liever zelf dan
  dat opties stil verdwijnen. Waarborg die ik erbij zet omdat de rangschikking
  dan scheef kan staan: zo'n optie krijgt ook de indicative-vlag, en het
  mislukken wordt 15 min gecacht zodat een kapotte scraper niet het tegoed
  opeet.

Opgewaardeerd door de eindreview, beide gefixt:
  - T10 (logregel bij overgeslagen aanbiedingen): van Minor naar "voor merge",
    omdat C2 het pad bereikbaar maakte.
  - T13 (SearchController stuurde exceptietekst als 400): idem — met C2 kreeg
    de gebruiker letterlijk 400 "Bedrag mag niet negatief zijn", een
    serverstoring gepresenteerd als invoerfout.

Eindreview bevestigde alle zes eerdere weerleggingen; geen enkele teruggedraaid.
Wel een terechte nuance bij de float-weerlegging uit T11: die was juist binnen
haar eigen vraag (afronden sloopt niets), maar ik heb de zoekruimte eromheen te
smal genomen — de niet-numerieke cast ernaast bleef staan en werd C2.

Openstaand ter beoordeling door de gebruiker:
  - twee grensconstanten zijn een schatting van de fixer, niet afgestemd:
    MAX_TICKET_PRICE_EUROS = 1.000.000, MAX_TRAIN_PRICE_EUROS = 10.000
  - de fixer voegde ongevraagd een plandocument van 854 regels toe in
    docs/superpowers/plans/2026-09-16-eindreview-fixes.md
  - I2: /api/search is op de VPS publiek en kost per aanroep Bright
    Data-credits. Geen auth, geen rate limiting. Vóór de eerste deploy regelen.
  - I6/I7: twee spec-eisen die het goedgekeurde plan heeft weggelaten (Plus-
    prijs naast Light tonen; KLM alleen tonen als het echt goedkoper is).
    Bewust besluiten, niet laten verdampen. I7 weegt zwaarder dan het leek:
    de spec verantwoordt de dunne "indicatief"-markering juist met het argument
    dat KLM al weggefilterd zou zijn bij een marginaal verschil.

## Tweede eindreview (opus) — blokkeerde opnieuw, twee nieuwe Critical

Beide zaten wéér in een naad, nu tussen DOMEIN en BEDRADING.

C1 — DE SCHATTING WAS FACTOR 100 MIS. TRAIN_PRICE_ESTIMATE=35 stond als euro's
  gedocumenteerd en werd als centen geconsumeerd. ZELF GEMETEN via kernelboot:
  parameter 35 -> Money::fromCents(35) -> EUR 0,35 in plaats van EUR 35,00.
  De sorteerfix werkte dus in de tests maar niet in het product.
  HOE IK HET MISTE: ik had die fix wel degelijk zelf nagemeten, maar gaf in
  mijn probe 3500 met de hand aan de handler mee — precies zoals de tests.
  Ik reproduceerde de blinde vlek in plaats van hem te vinden. De meting ging
  door het domein, niet door de bedrading. LES: bij alles wat uit configuratie
  komt, meten via de ECHTE container.
  OPGELOST DOOR EEN KOERSWIJZIGING VAN DE GEBRUIKER: hij vond sorteren met een
  schatting te ingewikkeld. Een optie met onbekende treinprijs doet nu NIET mee
  aan de sortering en komt achteraan, wel zichtbaar, zonder verzonnen bedrag.
  Daarmee verdween de hele variabele en dus ook de eenheidsfout — het probleem
  is weggenomen in plaats van gerepareerd.

C2 — MAATSCHAPPIJ KWAM VAN HET EERSTE VLUCHTBEEN. Google Flights geeft airline
  per been; de adapter nam leg[0] voor de hele reis. Gevolg (a): een been met
  Ryanair glipte door de AY/SK/KL-filter, want AllowedCarriers kijkt alleen naar
  itinerary->carrier. Gevolg (b): dezelfde reis kostte EUR 25 meer of minder
  afhankelijk van de VOLGORDE van de benen, en de SAS-eerst-variant verloor ook
  nog de indicative-markering.
  Zelfde testpatroon als altijd: BrightDataFlightSourceTest had GEEN ENKEL
  aanbod met twee segmenten. De adapter die patroon B moet bouwen werd nergens
  op patroon B getest.
  NA DE FIX ZELF GEMETEN: SAS+Ryanair -> overgeslagen; KLM+SAS en SAS+KLM geven
  allebei carrier=KL (duurste bagage, conservatief, volgorde-onafhankelijk).

Ook gefixt: Bright Data gaf bij HTTP 429 en bij een HTML-antwoord nog een 500
  (nagemeten: nu 0 resultaten, geen crash) — de treinbron had dat al.
  En de logger-bedrading lag nergens vast: het blok verwijderen liet 131/131
  groen. Nu valt er een test om. Zelf nagemeten, voor en na.

## Minor findings voor de eindreview

- T1: `api/composer.json` heeft `"php": ">=8.2"` terwijl het project 8.4 vereist.
  Door composer create-project gegenereerd. Overweeg `>=8.4`.
- T1: `api/.env.dev` bevat een hardcoded dev-APP_SECRET. Normaal Symfony-gedrag,
  `.env.local` staat in .gitignore. Geen actie nodig, wel bewust.
- T2: `Money::format()` gebruikt `%s` voor het eurodeel waar `%d` zuiverder is.
  Functioneel identiek. Geen gedragsrisico.
- T2: `test_rounding_does_not_drift` dekt maar één geval (10.1). Extra cases
  zoals 10.15 zouden het vertrouwen vergroten.
- T3: docblock `@param array<string, LoyaltyStatus>` belooft een sleutelstrategie
  die niet statisch afgedwongen wordt. Puur documentair.
- T4: **segmentvolgorde wordt niet gevalideerd.** `Itinerary` controleert per
  segment dat aankomst na vertrek ligt, maar niet dat segment 1 vertrekt ná
  aankomst van segment 0. Bij omgekeerde volgorde geeft `connectionMinutes()`
  een negatief getal, en `MaxConnectionDuration` (T7) laat `negatief <= 120`
  gewoon door. Niet bereikbaar via de Bright Data-bron, dus latent.
  Overweeg bij de eindreview een chronologiecheck in de Itinerary-constructor.
- T6: `coversHandLuggage()` is misleidend genoemd — geeft `true` voor SAS Silver
  terwijl de handbagage daar juist ingecheckt wordt. Naam suggereert dat de tas
  aan boord mag. Overweeg hernoemen naar `isHandLuggageCostFree()`.
- T6: test voor Finnair Silver controleert niet dat `checkedBagIncluded` false is;
  de SAS-test doet dat wel in beide richtingen. Klein gat in symmetrie.
- T10: `BrightDataFlightSource` slaat rotte aanbiedingen stil over (return null).
  Bij een structureel gewijzigd Bright Data-antwoord levert dat nul resultaten
  zonder signaal. Overweeg een teller of log-regel bij de eindreview.
- T11: de twee fixes bijten elkaar licht. Een écht gratis trein (0.0) levert
  na fix 1 terecht Money::zero() op, maar wordt door fix 2 vervolgens niet
  gecacht — niet fout, wel inconsistent. Academisch bij VR-prijzen.
- T11: cachesleutel 'vr.hel-tku.<datum>' gebruikt koppeltekens. PSR-6 staat
  dat toe; reviewer noemde het niet-blokkerend. Geen actie gedaan.
- T14: **"indicatief" is mager voor wat het moet waarschuwen.** Het staat als
  klein cursief woord naast de prijs, zonder uitleg dat de KLM-bagagetoeslag
  een schatting is en dat het echte bedrag bij boeking kan afwijken. Voldoet
  aan de letter van de spec ("zichtbare markering"), maar dit is het enige
  punt waar de app toegeeft dat het getal kan kloppen noch kloppen. Overweeg
  een title-attribuut of één zin erbij. BESLISSING VAN DE GEBRUIKER.
- T14: `api.ts` doet `return response.json()` zonder validatie; dat is
  impliciet `any`, dus strict-modus vangt een gewijzigd backendcontract niet.
  Een runtime-validatie (zod o.i.d.) is buiten het plan gelaten.
- T14: geen laadindicatie voor het ophalen van de geplande reizen bij het
  openen van de pagina; de knoppenbalk verschijnt met een sprong.
- T13: **SearchController stuurt de exception-tekst door als 400.** Het vangt
  `\InvalidArgumentException` breed af. Nu onschuldig (onbekende reiziger),
  maar Money en Itinerary gooien dezelfde soort uitzondering bij kapotte
  brondata. Dan krijgt de gebruiker een 400 met "Bedrag mag niet negatief
  zijn" — een serverprobleem gepresenteerd als een fout van de gebruiker.
  Overweeg een eigen uitzonderingstype voor wat de buitenwereld mag zien.
- T13: `api/config/reference.php` is meegewijzigd (twee when@dev-typeblokken
  verdwenen) zonder aanleiding uit het plan. Alleen autocompletehulp, geen
  runtime-effect. Overweeg of dit bestand in git thuishoort.
- T13: de presenter geeft `connectionMinutes` mee, dat niet in het
  voorbeeld-JSON van het plan staat (wel in de voorgeschreven code). Extra
  veld, niet brekend; taak 14 leest uit hetzelfde plan.
- T12: `test_options_that_break_the_rules_are_dropped` bewijst indirect. Hij
  controleert de basisprijs van de overlevende vlucht; in combinatie met
  assertCount(1) is dat voldoende, maar niet scherp gesteld.
- T12: TotalCostCalculator bewaakt zélf of een reis patroon A is voordat de
  treinprijs wordt opgeteld, terwijl de handler $trainCost aan álle reizen
  doorgeeft. Werkt correct (verdediging in de diepte), maar de echte barrière
  ligt lager dan je bij het lezen van de handler verwacht.
- T11: de zwakke plek blijft dat Money::zero() twee dingen betekent — "geen
  trein" en "Apify gaf niets bruikbaars". Taak 12 moet beslissen of een
  treinprijs van nul een reisoptie mag laten winnen.

---

# Eindreview-fixes — voortgang

Plan: docs/superpowers/plans/2026-09-16-eindreview-fixes.md
Startcommit: 375ac97

## Afgerond

Taak 1 (bevinding 1, patroon terugreis): compleet (commit 3098de4, review schoon)
  - Spec ✅, kwaliteit goedgekeurd, geen Critical/Important blockers.
  - Mutatietest bevestigd: terugzetten op alleen $destination laat de adapter-test falen.
  - Suite: 83 groen.
  - Minor voor eindreview: naam $viaHelsinki/commentaar suggereert "via Helsinki"
    terwijl de logica eindpunten toetst. Correct voor de huidige scope (AMS<->HEL,
    AMS<->TKU), maar een toekomstige AMS->HEL->FRA zou niet matchen.

Taak 2 (bevinding 2, onleesbare prijs): compleet (commits 3098de4..5323371, review goedgekeurd + fixronde)
  - Spec ✅, kwaliteit goedgekeurd. Guard: is_numeric() + < 0 + try/catch.
  - Mutatietest: zonder guard falen alle drie voorgeschreven tests.
  - Fixronde (5323371, alleen tests, geen productiecode):
    * '134,50' had geen test terwijl het letterlijk in de bevinding stond. Toegevoegd.
    * Reviewer noemde de try/catch "dood hout". NAGEMETEN EN ONJUIST: 9e18 passeert
      is_numeric() en de <0-check, maar (int)round(9e18*100) overflowt naar
      -3890459611768029184, dus Money gooit alsnog. De catch is het enige dat die
      zoekopdracht overeind houdt. Catch blijft staan, nu met test.
  - Suite: 88 groen.
  - Minor voor eindreview: 1e20 overflowt naar POSITIEVE garbage (1864712049423024128
    cent) en passeert alles — een zinloze reuzenprijs wordt een geldige optie.
    Onschadelijk voor de ranglijst (sorteert onderaan), maar het is vuil in de uitvoer.

Taak 3 (bevinding 3, logging): compleet (commits 5323371..cddeb77, review + fixronde)
  - Spec: reviewer gaf een spec-gap op services.yaml (niet gewijzigd). Terecht.
    De implementeerder had gemeten dat autowiring de logger al levert; die meting
    klopte (zelf nagedraaid: Arguments toont Service(logger)). Maar de opdracht
    vroeg letterlijk om bedrading, en het bestaande named-arguments-blok maakt het
    fragiel: een positional argument erbij en de logger verdwijnt stil.
    Fix cddeb77: $logger: '@logger' expliciet, geverifieerd in prod EN test env.
  - Fix M4: assertStringContainsString('1', ...) was een false negative -- de "1"
    matchte op de datum 2026-10-29. Mutatietest bewees het: teller op 2 vastzetten
    liet de OUDE assertion groen. Nu wordt teller + route samen getoetst.
  - Teller dekt alle return null-paden in toItinerary() (reviewer verifieerde alle acht).
  - Logt ook wanneer ALLE aanbiedingen sneuvelen -- precies het scenario uit de opdracht.
  - Suite: 89 groen.
  - Geen volle re-review na de fixronde: twee regels (yaml + assertion), beide met
    eigen verificatie (debug:container in 2 omgevingen, mutatietest). Bewust.

Taak 4 (bevinding 4, 400 vs 500): compleet (commits cddeb77..884be46, review + opruiming)
  - Spec ✅. UnknownTraveller in src/Domain/Traveller/, framework-vrij, strict_types.
    Traveller::byId() gooit hem; controller vangt alleen nog dat type af.
  - test_an_unknown_traveller_is_a_bad_request blijft groen (400), nieuwe
    test_a_data_source_error_is_a_server_error geeft 500.
  - Mutatietest: catch terugzetten op \InvalidArgumentException laat de 500-test falen.
  - Reviewer gaf een VOORWAARDELIJKE Critical: "bevestig dat FlightPriceSource in de
    testcontainer overschrijfbaar is". Zelf geverifieerd: services.yaml heeft onder
    when@test al `public: true` voor beide poorten. Critical vervalt. Extra bewijs:
    de [critical]-logregel in de suite-uitvoer citeert "Bedrag mag niet negatief zijn",
    tekst die alleen uit de testdubbel kan komen -- de override werkt aantoonbaar.
  - Opgeruimd (884be46): ongebruikte Direction-import, artefact uit mijn eigen plan.
  - Suite: 90 groen.
  - Important voor eindreview (reviewer, terecht): UnknownTraveller extends
    \InvalidArgumentException houdt de oude verwarring deels in stand -- een
    catch (\InvalidArgumentException) elders vangt hem nog steeds. BrightDataFlightSource
    heeft twee van die catches. Nu onbereikbaar (Traveller::byId komt daar niet),
    maar latent bij refactors. De opdrachtgever schreef dit extends expliciet voor.

## Eindreview (brede review over alle 4 bevindingen)

Twee ECHTE Criticals gevonden die de per-taak-reviews misten:
  C1: json_decode van 1e999 geeft INF; (int) round(INF*100) = 0 -> gratis spookvlucht
      die op de terugreis als EUR 22,50 (alleen de trein) BOVENAAN de goedkoopste-lijst
      kwam, boven een echte optie van EUR 102,50. Niet geteld, niet gelogd.
  C2: 92233720368547744 gaf een geldige Money, maar Money::add() liep over van int naar
      float -> TypeError (geen InvalidArgumentException!) -> 500 op de hele zoekopdracht.
      Precies de faalmodus die bevinding 2 moest wegnemen.
Fixronde 1 (3002f24..37255f3): guard op is_finite + bovengrens, logging bij ontbrekende
  collectie, PSR-3 placeholders, integratietest door de echte adapter, hernoeming.

Her-review vond dat C1 NIET dicht was:
  prijs 0 / 0.004 / -0.0 / 1e-9 passeerden nog steeds -> 0 cent -> zelfde spookvlucht.
  De guard was asymmetrisch: -0.004 geweigerd, +0.004 doorgelaten.
  Ook: dezelfde bug onaangeroerd in ApifyTrainPriceSource; is_array() ontbrak op de
  collectie-guard (scalar best_flights -> 500 zonder logregel); en de INF-test was
  vacuum (is_finite() slopen liet de hele suite groen).
Fixronde 2 (c988bc0..fe798ff): toets op CENTNIVEAU (dekt 0 en 0.004 in een keer),
  treinbron-filter incl. bovengrens (1e17 sloopte daar de zoekopdracht), is_array(),
  is_finite() geschrapt als aantoonbaar dode code, benoemde constante, scherpere teller.

Eindstand: 103 groen, werkboom schoon.
Eigen eindprobe door de echte adapter: 0 / 0.004 / INF / 1e17 alle vier overgeslagen,
geldige prijs 80 komt door als EUR 80,00.

---

# Treinprijs-onbekend — voortgang

Plan: docs/superpowers/plans/2026-09-16-treinprijs-onbekend.md
Branch: master (main branch, geen aparte feature-branch)
Startcommit: fe798ff

## Afgerond

Taak 1 (wijziging 4, UnknownTraveller -> DomainException): compleet (commits 50da14b..50f1de4, review schoon)
  - Spec goedgekeurd, kwaliteit goedgekeurd. Geen Critical/Important.
  - Tweede commit was noodzakelijk: TravellerTest verwachtte nog het oude oudertype.
  - Reviewer verifieerde met grep dat geen enkele catch (\InvalidArgumentException)
    UnknownTraveller ooit ving. De twee in BrightDataFlightSource vangen Segment-/
    Money-constructiefouten; Traveller::byId komt daar niet langs.
  - Suite: 103 groen.
  - MINOR voor eindreview:
    * TravellerTest::test_unknown_id_is_rejected toetst op \DomainException i.p.v.
      op het concrete UnknownTraveller::class. Even vaag als voorheen, geen regressie,
      maar het concrete type zou scherper zijn.
    * Geen test die expliciet vastlegt dat UnknownTraveller de InvalidArgumentException-
      familie verlaten heeft.
Taak 2 (wijziging 3, Money gooit bij INF/-INF/NAN): compleet (commit 1ec2d97, review schoon)
  - Spec goedgekeurd, kwaliteit goedgekeurd. Geen Critical.
  - Adapterguards geverifieerd ongemoeid door de reviewer. Extra bevinding:
    BrightDataFlightSource gebruikt Money::fromCents(), niet fromEuros() --
    dat pad raakt de nieuwe guard dus sowieso niet.
  - Tests zijn mutatie-afdwingend dankzij expectExceptionMessage('niet-eindige waarde').
  - Suite: 106 groen (103 + 3).
  - IMPORTANT voor eindreview (pre-existing, buiten scope van deze taak):
    Money::fromEuros(1e17) is eindig, passeert dus is_finite(), en loopt daarna
    over: (int) round(1e17 * 100) = -8446744073709551616. De constructor gooit
    wel, maar met de MISLEIDENDE tekst "Bedrag mag niet negatief zijn." De echte
    oorzaak is overflow. Gemeten in de container door de reviewer. Beide adapters
    hebben hier al een eigen bovengrens tegen, dus niet bereikbaar via de huidige
    aanroepers. Overweeg abs($euros) > PHP_INT_MAX / 100 in fromEuros().
Taak 3 (wijziging 1 deel A, interface + domeintypen): compleet (commit 0c41ad8, review schoon)
  - Spec goedgekeurd, kwaliteit goedgekeurd. Geen Critical/Important.
  - MUTATIETESTS door de reviewer gedraaid, beide gevangen:
    * |( $isHelRoute && !$trainCostKnown )-tak uit indicative weggehaald
      -> test_null_train_price_for_helsinki_pattern_marks_cost_unknown_and_indicative faalt
    * $trainCostKnown = null !== $trainCost weggehaald (altijd true)
      -> dezelfde test faalt op assertFalse($cost->trainCostKnown)
    Code teruggezet, git diff -- api/src/ leeg geverifieerd.
  - Covariantie bevestigd met live containertest: ApifyTrainPriceSource::priceFor(): Money
    is een geldige implementatie van de interface ?Money. Adapter niet aangepast (dat is taak 4).
  - Alle 12 aanroepen van new FixedTrainPriceSource vervangen; grep levert nul treffers.
  - Suite: 109 groen (106 + 3).
  - MINOR voor eindreview:
    * Geen test voor KLM + ViaHelsinki + null treinprijs. De logica klopt (beide
      indicative-takken vuren), maar geen test bewijst dit specifieke kruispunt.
    * test_known_train_price_sets_trainCostKnown_true toetst niet ook assertFalse(indicative);
      de naam suggereert meer dekking dan geleverd.
    * InMemoryFlightPriceSourceTest regel 44 dereferentieert ->cents op een ?Money
      zonder null-check. Nu veilig (withPrice), kwetsbaar bij toekomstige wijziging.
Taak 4 (wijziging 1 deel B, adapter + presenter + frontend): compleet (commit 3b9b00e, review goedgekeurd + 1 Important doorgeschoven naar taak 5)
  - Spec goedgekeurd, kwaliteit goedgekeurd. Geen Critical.
  - Cache-schildwacht door reviewer aangevallen en solide bevonden: CACHE_NULL_SENTINEL
    ('null') wordt met === getoetst VOOR de (int)-cast, dus "null gecacht", "prijs is
    0 cent" en "cache miss" blijven onderscheidbaar. Ook nagegaan voor een Redis-achtige
    backend die alles als string teruggeeft: '0' === 'null' is false, correct.
  - MUTATIETESTS (4 gedraaid door de reviewer, 3 gevangen):
    * return null -> return Money::zero() in fetch()  -> GEVANGEN
    * 'trainCostKnown' => true hardcoded in presenter -> GEVANGEN
    * v-if="row.unknown" weg uit OptionCard           -> GEVANGEN
    * TTL_SECONDS <-> TTL_NULL_SECONDS verwisseld     -> NIET GEVANGEN (alle 11 groen)
  - Suites: PHP 111 groen, frontend 9 groen, vue-tsc build schoon.
  - IMPORTANT doorgeschoven naar taak 5: de TTL-WAARDEN zelf zijn ongedekt. De test
    telt alleen het aantal HTTP-aanroepen, wat bewijst DAT er gecacht wordt maar niet
    HOE LANG. Verwisseling is de ernstige richting: null 24 uur vast (kapotte scraper
    probeert een dag niet opnieuw) en geldige prijs maar 15 minuten (vreet juist tegoed).
    Taak 5 gaat over precies deze cache, dus daar hoort de test thuis.
  - MINOR voor eindreview: de Vue-assertie not.toContain('€ 0,00') zou ook slagen als
    de treinregel helemaal wegviel. not.toContain('€') ware robuuster.
Taak 5 (wijziging 2, cache-TTL getoetst): compleet (commits 5d1052f..b341967, review + fixronde + her-review schoon)
  - Stap 1-constatering: test_a_null_result_is_cached_for_15_minutes bestond al uit taak 4.
    Het werkelijke werk was het Important uit taak 4: de TTL-WAARDEN waren ongedekt.
  - Aanpak: twee PSR-6-dubbels (SpyCacheItemPool + SpyCacheItem) die de waarde vastleggen
    die aan expiresAfter() wordt meegegeven. ArrayAdapter afgewezen omdat die de expiry
    als absolute timestamp bewaart (afronding + terugrekenen nodig).
  - Twee TTL-tests toegevoegd. MUTATIETEST door reviewer herhaald: TTL-constanten
    verwisseld -> beide vallen om met "86400 is identical to 900" resp. omgekeerd.
  - FIXRONDE (b341967) op een Important uit de eerste review: SpyCacheItemPool::save()
    riep $item->get() aan op een MISS-item, en get() geeft bij isHit=false altijd null.
    De spy sloeg dus altijd null op. Gevolg als iemand hem later voor twee aanroepen
    inzet: isHit() true, get() null -> productiecode maakt Money::fromCents((int) null)
    = 0 cent in plaats van "onbekend". Dat is exact de gratis-trein-bug, maar dan
    verstopt in het meetgereedschap. Opgelost met getRawValue(), buiten de PSR-interface.
  - Twee regressietests erbij die de spy zelf toetsen (sentinel-pad en geldig-prijspad).
    Mutatiebewijs: save() terug op get() -> beide vallen om met
    "Money(cents=0) is null" en "0 is identical to 2250".
  - Her-review: beide mutatietests opnieuw gedraaid en bevestigd, api/src/ ongemoeid,
    werkkopie schoon. Spec en kwaliteit goedgekeurd.
  - Suite: 115 groen.
  - MINOR voor eindreview:
    * SpyCacheItemPool::getItem() geeft een vers item terug waarvan getRecordedTtl()
      altijd null is. Onschadelijk (TTL wordt via $spy->recordedTtls gelezen), maar
      een comment zou het expliciet maken.
    * De testklasse heeft nu ~4 tests die "null wordt gecacht" vanuit iets andere
      hoeken toetsen. Overlap is verdedigbaar maar het benoemen waard.

## Eindreview (breed, over alle vier wijzigingen)

Drie mutaties gedraaid die de per-taak-reviews niet gedaan hadden. TWEE OVERLEEFDEN:
  M1: CACHE_NULL_SENTINEL 'null' -> 0        -> OVERLEEFT, 115/115 groen
  M2: handler-tak ": null" -> ": Money::zero()" -> OVERLEEFT (equivalente mutant;
      bewijst juist dat de null geen patroon-A-optie kan besmetten)
  M3: indicative-tak + "&& $baggageCost->isZero()" -> OVERLEEFT, 115/115 groen

End-to-end pad zelf nagedraaid door de echte presenter met withUnknownPrice():
  chris:   AY via_hel_train total EUR 100,00 indicative=true  trainCostKnown=false
           SK through       total EUR 130,00 indicative=false trainCostKnown=true
  partner: SK through       total EUR 130,00 indicative=false trainCostKnown=true
           AY via_hel_train total EUR 135,00 indicative=true  trainCostKnown=false
Geen schakel waar de informatie wegvalt. Markering staat in alle gevallen waarin
de rangschikking scheef staat (indicative is een OR, beide takken vuren los).
new TotalCost() heeft precies een aanroeper, die het vijfde argument altijd expliciet
meegeeft -- de default true is dode code, geen aanroeper krijgt hem ten onrechte.
Sentinel in beide richtingen typevast bevestigd via een echte FilesystemAdapter.

Fixronde (b559c92), drie gaten gedicht:
  1. Docblock van TrainPriceSource beloofde null bij "quota overschreden". Gemeten:
     quota 429 en http 500 GOOIEN beide (getContent() gooit op non-2xx), niets vangt
     dat af -> HTTP 500 op de hele zoekopdracht. Alleen lege lijst en hernoemd veld
     geven echt null. Docblock gecorrigeerd, GEDRAG BEWUST ONGEWIJZIGD -- of een
     quota-fout naar "onbekend" moet degraderen is een beslissing van de eigenaar.
  2. Levende mutant op de indicative-markering, op het STANDAARDPAD: App.vue zet
     traveller default op 'partner', en partner betaalt EUR 35 bagage op Finnair.
     Precies de combinatie waar de mutant ontsnapte. Test toegevoegd
     (test_null_train_price_with_non_zero_baggage_is_still_indicative),
     mutatiebewijs "Failed asserting that false is true".
  3. Levende mutant op de schildwacht: sentinel op 0 zetten liet alles groen, terwijl
     een ECHTE gratis rit dan bij de tweede aanroep "onbekend" werd -- de spiegel van
     de oorspronkelijke bug, met 24 uur cache eronder. De null-kant was drie keer
     gedekt; test_a_zero_euro_price_is_not_filtered_out deed maar EEN aanroep en raakte
     de cache-terugweg nooit. Uitgebreid met een tweede aanroep, mutatiebewijs
     "Failed asserting that null is not null".

Eindstand: PHP 116 groen, frontend 9 groen, vue-tsc build schoon, werkkopie schoon.
Planbestand alsnog gecommit (d76ac02) -- stond untracked terwijl de zusterplannen
wel in git staan.

Verslag: .superpowers/sdd/treinprijs-onbekend-report.md

## Derde eindreview + overdracht

Zie **OVERDRACHT.md** in deze map voor: de volledige uitkomst van de derde
eindreview (die staat nergens anders), de ongecommitte werkkopie met één falende
frontendtest, de beslissingen van de eigenaar, en het naad-patroon dat alle zes
Critical-bevindingen van deze branch verbindt.

## Sessie 3 (17 sep 2026): ignav werkt echt, en de naad die daarbij bovenkwam

De eigenaar heeft een echte IGNAV_API_KEY ingevuld en de app voor het eerst met de
hand gedraaid: **zinnige lijst, herkenbare maatschappijen, plausibele bedragen.**
Eerste bewijs buiten de tests om dat ignav -> adapter -> domein -> UI klopt.

Twee waarnemingen uit dat draaien:

1. "KLM toont indicatief" -- correct gedrag, geen bug. FareUpgradeRates::ESTIMATED
   bevat alleen KL omdat KLM ruimbagage dynamisch beprijst.
2. "Het duurde meerdere seconden" -- deels een echte fout. TRAIN_COST_ENABLED zat
   alleen in TotalCostCalculator, maar SearchFlightsHandler deed het ophalen. Elke
   zoekopdracht met een HEL-optie startte dus een Apify-actor-run waarvan de uitkomst
   direct werd weggegooid: seconden wachttijd EN een van de ~20-50 runs per maand.
   ACHTSTE naad, zelfde vorm als de vorige zeven: schakelaar in de ene component,
   kosten in de andere.

Fix (44453cc): TotalCostCalculator::usesTrainCost(); de handler vraagt het daar op,
zodat de schakelaar op EEN plek blijft wonen. Twee tests door de echte container,
beide standen, die het AANTAL AANROEPEN van de treinbron toetsen -- niet het
eindbedrag, want een weggegooide uitkomst is aan het resultaat niet te zien. De
uit-test was rood voor de fix en groen erna; dat is meteen het mutatiebewijs.

Geheimen: de key is verplaatst naar api/.env.local (gitignored). api/.env is door git
gevolgd en stond op het punt de key mee te nemen; dat bestand is nu weer gelijk aan
HEAD. Gemeten, niet aangenomen: bootEnv() in de container laadt de key in APP_ENV=dev
en NIET in APP_ENV=test -- precies goed, want geen test mag het netwerk op.

Eindstand: PHP 148 groen, frontend 14 groen, werktree schoon.
Bewust geparkeerd door de eigenaar: de twee ignav-calls (AMS->HEL, AMS->TKU) parallel
uitvoeren; dat verandert de vorm van de FlightPriceSource-poort.

## Boekingslink (3 okt 2026) — `docs/plans/boekingslink/`

Vier gates goedgekeurd, zes slices, alles op master:
- Slice 1 tracer bullet `3cef90f` · slice 2 echte link `1f252a9` · slice 3 geen
  directe link + 400 `5b9307b` · SAS zelf gebouwd `8290829` · slice 4 502
  `e0d7fd3` · slice 5 cache `653d105` · slice 6 ADR-0006 + overdracht.
- Per slice: tests vooraf, mutatieronde, bewijs in de echte app (curl en/of
  Chrome). Mutaties die groen bleven en waarom: poort-alias en `@cache.system`
  (Symfony autowiret/cachet net zo goed — geen gedragsverschil); `return` na
  `navigate` (echt gat → test aangescherpt).
- Ontdekt door de eigenaar: SAS-zoekprijs is de KLM-codeshareprijs (± dubbel).
  Besluit: SK-opties "prijs onbekend" — volgende feature.
- Verbruik ignav vandaag: 7 zoek-calls en 8 link-calls (plus 2 mislukte met een verzonnen id, tellen niet mee).
Eindstand: PHP 176, frontend 77, build groen, `--network none` groen.
