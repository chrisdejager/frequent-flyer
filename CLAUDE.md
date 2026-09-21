# Leiden ↔ Turku vluchtzoeker

Zoekt de goedkoopste reis tussen Leiden en Turku. Het totaal is de ticketprijs
plus de VR-trein Helsinki⇄Turku waar van toepassing. HEL<>AMS toont alleen
directe vluchten, TKU<>AMS alleen vluchten met precies 1 overstap.

Bagagekosten tellen bewust niet mee (versimpeling, 2026-09-21) — de eerdere
per-reiziger-berekening (`BaggagePolicy`/`FareUpgradeRates`) rekende ook mis
bij een expliciet gekozen reiziger. Terughalen kan uit de git-historie vóór
deze wijziging als dat ooit weer nodig is.

## Lees dit eerst bij een nieuwe sessie

- **`.superpowers/sdd/OVERDRACHT.md`** — waar het werk staat, wat er open is, en
  welke beslissingen de eigenaar heeft genomen. Begin hier.
- `.superpowers/sdd/progress.md` — logboek van alle taken en reviewrondes.
- `docs/superpowers/specs/2026-09-16-leiden-turku-vluchtzoeker-design.md` — de spec.

Beide `.superpowers`-bestanden staan buiten git (scratch), maar zijn wel de
actuele stand van zaken.

## Draaien

```bash
docker compose exec -T php php bin/phpunit          # PHP-suite
docker compose run --rm node npm run test           # frontend
docker compose run --rm node npm run build
docker compose up                                   # app op http://localhost:8080
```

Bewijzen dat geen test het netwerk raakt:

```bash
docker run --rm --network none -v "$PWD/api:/app" -w /app flights-php:latest php bin/phpunit
```

PHPUnit-config heet `phpunit.dist.xml`; werkpad in de container is `/app` (= `api/`).

## Randvoorwaarden

- PHP 8.4 / Symfony 7.4 LTS + Vue 3. **Geen Python** — harde eis.
- `declare(strict_types=1)` overal; `src/Domain/**` framework-vrij (er is een
  architectuurtest die daarop controleert).
- Geldbedragen altijd in hele centen als int.
- Maatschappijen AY/SK/KL; luchthavens AMS/HEL/TKU/ARN/CPH; overstap ≤120 minuten.
- **Geen enkele test mag het netwerk op.** Externe tegoeden zijn klein: Bright
  Data 5.000 credits/maand, Apify ~20–50 draaibeurten/maand.

## Werkwijze die deze codebase heeft geleerd

Drie eindreviews vonden zes ernstige fouten. **Geen enkele zat in een losse
component** — allemaal in de naad ertussen: adapter versus regel, domein versus
bedrading, aanroeper versus mapper.

- Meet gedrag door de **echte container** (`WebTestCase`), niet langs de
  configuratie heen. Een fout van factor 100 in een parameter bleef onzichtbaar
  omdat zowel de tests als een handmatige controle de waarde zelf meegaven.
- Wantrouw een test die om een probleem heen werkt in plaats van het te dichten.
- Een fix zonder test die faalt zónder die fix, is geen fix. Bewijs het met een
  mutatie.
- Verifieer reviewbevindingen empirisch vóór je ze aanneemt óf afwijst.
