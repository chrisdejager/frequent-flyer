# Leiden ↔ Turku vluchtzoeker

Zoekt de goedkoopste reisopties tussen Leiden en Turku, met een totaalprijs
die de VR-trein en de bagagekosten per reiziger meerekent.

- [Ontwerp](docs/superpowers/specs/2026-09-16-leiden-turku-vluchtzoeker-design.md)
- [Implementatieplan fase 1](docs/superpowers/plans/2026-09-16-vluchtzoeker-fase-1.md)

## Instellen

Kopieer `api/.env` naar `api/.env.local` en vul in:

| Variabele | Waar vandaan |
|---|---|
| `BRIGHTDATA_TOKEN` | brightdata.com — 5.000 gratis credits per maand |
| `BRIGHTDATA_ZONE` | naam van je SERP-zone |
| `APIFY_TOKEN` | apify.com — $5 gratis tegoed per maand |
| `FARE_UPGRADE_AY` | Finnair Light→Plus in euro's — **16** (vast tarief) |
| `FARE_UPGRADE_KL` | KLM Light→Plus in euro's — **25** (schatting; dynamisch geprijsd) |

De app **start niet** zonder `FARE_UPGRADE_AY` en `FARE_UPGRADE_KL`. Dat is
opzet: doorrekenen met €0 zou Light-tarieven structureel te goedkoop laten lijken.

## Lokaal draaien

```bash
docker compose up
```

Open http://localhost:8080

## Testen

```bash
docker compose exec -T php php bin/phpunit
docker compose run --rm node npm run test
```

## Naar de VPS

```bash
docker compose run --rm node npm run build
docker compose -f compose.yaml -f compose.prod.yaml up -d
```
