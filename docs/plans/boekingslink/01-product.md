# Product: Boeken-knop per vlucht

## Problem
"Als ik in de lijst een vlucht heb gevonden, moet ik nu zelf naar de site van
Finnair, KLM of SAS, dezelfde route en datum opnieuw intikken en tussen alle
vluchten de juiste terugzoeken. Ik wil gewoon op 'Boeken' klikken en meteen bij
die vlucht op de site van de maatschappij uitkomen. Maar het opvragen van zo'n
link kost een stukje van mijn gratis tegoed, dus alleen voor de vlucht waar ik
echt op klik — niet voor alles wat in de lijst staat."

## Success metric
**Van "Boeken" naar het boekingsscherm van de juiste vlucht in één klik, zonder
dat het zoeken duurder wordt.** Gemeten aan:
- een zoekactie verbruikt precies evenveel tegoed als nu (nul extra per vlucht
  in de lijst);
- elke klik op "Boeken" kost hoogstens één opvraging, en een tweede klik op
  dezelfde vlucht kost niets extra;
- na de klik staat binnen ~3 seconden in een nieuw tabblad het boekingsscherm
  van die maatschappij met die vlucht.

## Announcement — the blog post before the feature
Elke vlucht in de lijst heeft nu een knop "Boeken". Klik erop en er opent een
nieuw tabblad met het boekingsscherm van de maatschappij zelf — Finnair, KLM of
SAS — met jouw vlucht en datum al ingevuld. De link wordt pas opgehaald op het
moment dat je klikt, dus zoeken blijft net zo zuinig als altijd. Klik je later
nog eens op dezelfde vlucht, dan gaat het direct. Kan de maatschappij zelf geen
link geven, dan zie je dat meteen en kun je verder zoeken op hun site.

## Keuzes in dit scherm (ter goedkeuring)
1. **Knop "Boeken" rechts op elke vluchtregel**, onder het totaalbedrag. Klikken
   op de knop selecteert de regel níet (dat blijft de rest van de regel doen).
2. **Nieuw tabblad opent direct** met "Boekingslink ophalen…" en springt door
   naar de maatschappij zodra de link er is. (Zo houdt de browser het tabblad
   niet tegen als pop-up.)
3. **Alleen de maatschappij zelf.** Biedt alleen een reisbureau (OTA) een link,
   dan sturen we je daar níet heen; het tabblad zegt "Geen directe link bij
   Finnair gevonden" met een gewone link naar de homepage van de maatschappij.
4. **Tweede klik op dezelfde vlucht** gebruikt de al opgehaalde link (zolang de
   pagina openstaat); geen nieuwe opvraging.
5. **Mislukt het ophalen** (storing, tegoed op), dan zegt het tabblad dat in
   gewone taal; de lijst blijft gewoon staan.
6. **Retour:** heen en terug blijven twee losse boekingen — elke kolom heeft zijn
   eigen knoppen (past bij "ik boek graag losse reizen").

## Screens
- `mockups/boeken-knop.html` — vluchtlijst met "Boeken"-knoppen, het nieuwe
  tabblad in drie toestanden (ophalen, geen directe link, fout).
