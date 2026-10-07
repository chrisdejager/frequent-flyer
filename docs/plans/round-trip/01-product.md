# Product: Heen en terug op één scherm

## Problem
"Ik wil een heen- én terugreis plannen, maar nu moet ik twee keer zoeken, de
richting omzetten en zelf onthouden wat de goedkoopste heenvlucht kostte om het
bij de terugvlucht op te tellen. Ik boek toch altijd twee losse enkele reizen,
dus ik wil gewoon beide datums invullen en alles in één keer zien."

## Success metric
**Eén zoekactie voor een volledige retour, in plaats van twee.** Gemeten aan het
scherm zelf: na één klik op "Zoek" staan de opties voor de heenreis, de opties
voor de terugreis en het goedkoopste totaal van beide tegelijk in beeld.
Randvoorwaarde: het verbruik van de gratis vluchtdata blijft gelijk aan twee
losse zoekacties (dus geen extra kosten per retour).

## Announcement — the blog post before the feature
Je kunt nu een heen- en een terugdatum tegelijk invullen. De vluchtzoeker zoekt
beide reizen in één keer en zet ze naast elkaar: links de heenreis, rechts de
terugreis, elk gesorteerd van goedkoop naar duur. Bovenaan staat wat de
goedkoopste combinatie samen kost. Klik je een andere vlucht aan, dan rekent het
totaal direct mee. Omdat je heen en terug los boekt, kun je vrij combineren —
KLM heen en Finnair terug is prima. Wil je toch maar één kant op? Laat de
terugdatum leeg.

## Keuzes in dit scherm (ter goedkeuring)
1. **Startland kiezen:** "Vertrek vanuit Nederland" of "Vertrek vanuit Finland";
   de terugreis is automatisch de andere kant op.
2. **Terugdatum is optioneel.** Leeg = enkele reis, zoals nu.
3. **Twee kolommen naast elkaar** op een breed scherm; op een smal scherm
   onder elkaar (eerst heen, dan terug).
4. **Totaalbalk bovenaan:** standaard de goedkoopste heen + goedkoopste terug.
   Klik je een andere vlucht aan, dan verandert het totaal mee.
5. **Terugdatum mag niet vóór de heendatum liggen**; dan een melding en niet zoeken.
6. **Geplande reizen** (de knopjes bovenaan) vullen de datum van die reis in
   bij de juiste kant; ze worden niet automatisch aan elkaar gekoppeld.
7. Elke kant laat zijn eigen meldingen zien (bijv. "geen opties op deze datum")
   zonder dat de andere kant verdwijnt.

## Screens
- `mockups/search-round-trip.html` — zoekformulier met twee datums, resultaten
  in twee kolommen, totaalbalk met de gekozen combinatie.
