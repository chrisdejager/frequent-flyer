# Leiden ↔ Turku vluchtzoeker

Zoekt de goedkoopste reisopties tussen Leiden en Turku, met een totaalprijs
die de VR-trein meerekent (bagagekosten bewust niet, zie ADR-0005).

- [Stand van zaken](docs/plans/README.md)
- [Ontwerp fase 1](docs/plans/vluchtzoeker-fase-1/spec.md)
- [Implementatieplan fase 1](docs/plans/vluchtzoeker-fase-1/plan.md)

## Instellen

Kopieer `api/.env` naar `api/.env.local` en vul in:

| Variabele | Waar vandaan |
|---|---|
| `BRIGHTDATA_TOKEN` | brightdata.com — 5.000 gratis credits per maand |
| `BRIGHTDATA_ZONE` | naam van je SERP-zone |
| `APIFY_TOKEN` | apify.com — $5 gratis tegoed per maand |
| `FARE_UPGRADE_AY` | Finnair Light→Plus in euro's — **16** (vast tarief) |
| `FARE_UPGRADE_KL` | KLM Light→Plus in euro's — **25** (schatting; dynamisch geprijsd) |

Zonder `FARE_UPGRADE_AY` en `FARE_UPGRADE_KL` **weigert de eerste zoekopdracht**
met een 500 — ontbrekend levert `Environment variable not found`, leeglaten
levert `Non-numeric env var ... cannot be cast to float`. Dat is opzet:
doorrekenen met €0 zou Light-tarieven structureel te goedkoop laten lijken.

Let op dat de container zelf wél opstart; je merkt een ontbrekende waarde dus
pas bij de eerste zoekopdracht, niet bij het deployen. Doe na een deploy één
zoekopdracht om te controleren dat de configuratie compleet is.

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
