# Product: SAS-prijs onbekend

## Problem
"Bij SAS zie ik in de vluchtzoeker € 374, maar op flysas.com kost dezelfde vlucht
€ 193. De app zet SAS daardoor onderaan, terwijl het misschien de goedkoopste reis
naar Turku is. Ik wil nooit een codeshare-prijs als dé prijs zien — ik boek altijd
bij de maatschappij zelf."

## Success metric
Aantal SAS-opties dat met een niet-SAS-prijs tussen de gerangschikte opties staat:
nu 2 van 2 (gemeten 29-10 en 26-11), doel **0**. Te meten met één zoekopdracht
AMS→TKU: geen enkele SAS-kaart boven de scheiding "Niet gerangschikt".

## Announcement — the blog post before the feature
SAS deelt op dit moment zijn prijzen niet met zoekmachines; wat je daar ziet is
het tarief van KLM voor hetzelfde toestel, vaak het dubbele. De vluchtzoeker
toont SAS-vluchten daarom voortaan met **"prijs onbekend"** en zet ze apart onder
de gerangschikte opties, met een knop die direct naar die vlucht op flysas.com
gaat. Zo zie je nooit een prijs die niet van SAS zelf komt, en mis je toch geen
vlucht. Zodra SAS weer eigen prijzen doorgeeft, gaat het met één schakelaar terug.

## Screens
- `mockups/zoekresultaat.html` — heenreis-kolom: gerangschikte opties, daaronder
  "Niet gerangschikt — prijs onbekend" met de SAS-kaarten; plus de totaalbalk
  wanneer je een SAS-kaart kiest.
