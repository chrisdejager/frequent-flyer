# Leiden ↔ Turku vluchtzoeker — intake

Overgenomen uit het Claude-artifact (screenshots, 16 sep 2026).
Artifact-URL: https://claude.ai/artifact/FB5P15iouUZx7ZRCn8qRGx

---

## Wat al bekend was (context bovenaan het formulier)

- AMS ⇄ HEL alleen direct. AMS ⇄ TKU precies 1 overstap, max ~2 uur.
- Naar HEL: aankomst uiterlijk ~21:00 (i.v.m. trein/bus naar Turku). Favoriet: middagvlucht Finnair.
- Vanuit HEL: vertrek niet vóór ~12:00.
- Alleen Finnair, SAS, KLM. Finnair/SAS eerst (silver), KLM alleen als goedkoper.
- Altijd losse enkele reizen, zodat je los kunt annuleren.
- Voorkeur via Helsinki (rustiger), maar trein/bus HEL ⇄ Turku meetellen in de totaalprijs.
- SAS is het snelst van deur tot deur.
- Planning staat een half jaar vooruit: wie reist, welke richting.

---

## DOEL

**Wat moet het systeem vooral doen?**
Opties: Geplande reizen bewaken + melding · **Op verzoek zoeken** ← gekozen · Overzicht planning + prijsverloop

> Prio is tonen van goedkoopste mogelijkheden voor een datum

---

## TICKETS & PRIJS

### Bagage
Opties: Altijd alleen handbagage · Soms ruimbagage · Verschilt per reis — *(geen optie aangeklikt)*

> Neem altijd de light economy, maar laat de plus prijs zien voor los handbagage bijkopen
> voor finnair en klm en tot hoe lang van te voren de goedkoopste.
> Bij sas kunnen we met silver gratis ruimbagage mee. We hebben allebei silver bij sas, maar
> bij finnair heb ik alleen silver en daar heb ik daarom gratis handbagage. We hebben dus geen
> gratis handbagage bij sas maar dan nemen we de handbagage als ruimbagage mee.

### Overstap naar/van Turku: één ticket of zelf overstappen?
**Alleen één doorgaand ticket** ← gekozen · Zelf overstappen mag ook

> Voorkeur voor Stockholm, Copenhagen als tweede. Volgens mij zijn er geen andere overstappen
> die zinvol zijn.

### Hoe flexibel zijn de datums?
**Vaste dag** ← gekozen · ±1 dag als het veel scheelt · Per reis anders

> Maar als het veel scheelt gaan we wel kijken of er iets mogelijk is. Vooral mijn vriendin is
> flexibel in dagen. Ik zit wel echt vast aan mijn zorg afspraken met coouder.

### Tijd, rust en status tegenover geld
*Hoeveel euro extra mag een optie kosten voordat de goedkopere wint?*

> Dat is afhankelijk van de trein kosten met vr.fi. dus het zou heel handig zijn om die kosten
> ook er bij te kunnen nemen.

### Helsinki-Vantaa ⇄ Turku
**Trein (VR)** ← gekozen · Bus

> Duidelijke voorkeur voor trein. Waarom is de vraag belangrijk wie betaald?

---

## VOOR WIE

### Zoekt het ook voor je vriendin vanaf haar kant?
**Ja, dezelfde regels** ← gekozen · Ja, maar met eigen regels · Alleen voor mij

> Haar lidm[aatschap] … vanuit Turku…
>
> ⚠️ **ONVOLLEDIG** — deze toelichting viel tussen twee screenshots weg.

---

## MELDINGEN & DRAAIEN

### Waar wil je het resultaat zien?
E-mail · Telegram · Pushmelding telefoon · **Webpagina / dashboard** ← gekozen

*(geen toelichting ingevuld)*

### Wanneer wil je een seintje?
Opties: Onder een vaste prijsgrens · Bij flinke daling · Advies "nu boeken" · Vast overzicht (bv. wekelijks) — *(geen optie aangeklikt)*

> Ik wil vooral een seintje bij korting periode, dus dat het lager is dan de normale goedkoopste
> prijs. Dat gebeurd namelijk onvoorspelbaar.
> Normale goedkoopste prijs bij finnair is naar Helsinki 134, naar Amsterdam vanaf Helsinki 80.
> Van sas weet ik het niet zo goed. Volgens mij iets van 150 naar Turku toe en 90 naar Amsterdam.
> Prijs stijging voor reisdatums is een high alert. Die hadden we eigenlijk al moeten boeken.
> Maar bij sas is dat wel erg ver in de toekomst volgens mij.

**Referentieprijzen (normale goedkoopste, enkele reis):**

| Maatschappij | Route | Prijs | Zekerheid |
|---|---|---|---|
| Finnair | AMS → HEL | €134 | zeker |
| Finnair | HEL → AMS | €80 | zeker |
| SAS | → TKU | ~€150 | onzeker |
| SAS | → AMS | ~€90 | onzeker |

### Hoe vaak checken, en waar mag het draaien?
Frequentie: Dagelijks · Paar keer per week · Wekelijks — *(geen optie aangeklikt)*
Locatie: Op mijn laptop · **Server / Raspberry Pi / NAS** ← gekozen · Cloud · Weet ik niet

### Budget voor een prijsbron
Alleen gratis · Tot ~€10/mnd · Tot ~€30/mnd · **Hangt ervan af** ← gekozen

*(hint in formulier: gratis betekent meestal scrapen van zoeksites, en dat gaat vaker kapot)*

### Acties en aanbiedingen
Nieuwsbrieven in Gmail mogen gelezen worden · Niet nodig — *(geen optie aangeklikt)*

> Misschien kun je zelf een api of website scannen voor deze informatie

---

## PLANNING

### Waar staat de planning nu?
**Google Calendar** ← gekozen · Spreadsheet · Notities / chat · In ons hoofd

Geplakte planning:

```
25 okt zij naar Nederland
29 okt zij naar finland
```

---

## Open punten

1. Toelichting bij "Zoekt het ook voor je vriendin" is afgekapt (lidmaatschap + vanuit Turku).
2. Bagage: geen van de drie opties aangeklikt — de tekst impliceert "verschilt per maatschappij".
3. Meldingsmoment: geen optie aangeklikt, maar de tekst beschrijft feitelijk twee triggers
   (korting onder normaalprijs + prijsstijging op geplande reisdatum = high alert).
4. Checkfrequentie niet gekozen.
5. Vraag terug van gebruiker: *"Waarom is de vraag belangrijk wie [de trein] betaalt?"*
