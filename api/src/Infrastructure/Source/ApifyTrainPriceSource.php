<?php

declare(strict_types=1);

namespace App\Infrastructure\Source;

use App\Domain\Flight\Money;
use App\Domain\Port\TrainPriceSource;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class ApifyTrainPriceSource implements TrainPriceSource
{
    private const ACTOR = 'studio-amba~vr-scraper';
    private const TTL_SECONDS = 86400;
    private const TTL_NULL_SECONDS = 900; // 15 minuten: mislukken cachen om Apify-tegoed te sparen

    /**
     * Bovengrens voor een treinkaartje HEL–TKU in euro.
     * Een VR-kaartje kost enkele tientjes; alles daarboven is een scraperartefact.
     * Zonder deze grens loopt (int) round($euros * 100) over naar een negatief
     * getal, waarop Money een InvalidArgumentException gooit — HTTP 500.
     */
    private const MAX_TRAIN_PRICE_EUROS = 10_000.0;

    /**
     * Schildwacht-waarde die in de cache staat als de scraper niets bruikbaars leverde.
     * Onderscheidt "null gecacht" van "prijs is 0 cent" en van "cache miss".
     */
    private const CACHE_NULL_SENTINEL = 'null';

    public function __construct(
        private HttpClientInterface $http,
        private CacheItemPoolInterface $cache,
        private string $apiToken,
    ) {
    }

    public function priceFor(\DateTimeImmutable $date): ?Money
    {
        $item = $this->cache->getItem('vr.hel-tku.'.$date->format('Y-m-d'));

        if ($item->isHit()) {
            $cached = $item->get();

            if (self::CACHE_NULL_SENTINEL === $cached) {
                return null;
            }

            return Money::fromCents((int) $cached);
        }

        $price = $this->fetch($date);

        if (null === $price) {
            $item->set(self::CACHE_NULL_SENTINEL);
            $item->expiresAfter(self::TTL_NULL_SECONDS);
        } else {
            $item->set($price->cents);
            $item->expiresAfter(self::TTL_SECONDS);
        }

        $this->cache->save($item);

        return $price;
    }

    private function fetch(\DateTimeImmutable $date): ?Money
    {
        $response = $this->http->request('POST', sprintf(
            'https://api.apify.com/v2/acts/%s/run-sync-get-dataset-items?token=%s',
            self::ACTOR,
            urlencode($this->apiToken),
        ), [
            'json' => [
                'from' => 'Helsinki',
                'to' => 'Turku',
                'date' => $date->format('Y-m-d'),
            ],
        ]);

        $rows = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        $prices = array_filter(array_map(
            static function (array $row): ?float {
                $raw = $row['priceEur'] ?? null;

                // Filter onbruikbare prijzen vóór de float-cast:
                // - niet-numeriek (N/A, lege string, gewone strings) → null
                // - absurd hoog (incl. INF, want INF > MAX_TRAIN_PRICE_EUROS is waar) → null
                // - negatief → null (een negatieve treinprijs is geen bruikbare prijs; -INF valt hier ook onder)
                // Prijs 0.0 is geldig: een gratis rit is mogelijk.
                if (!is_numeric($raw)) {
                    return null;
                }

                $price = (float) $raw;

                // De bovengrens vangt tevens INF af (INF > MAX_TRAIN_PRICE_EUROS is waar),
                // waardoor een aparte is_finite()-toets overbodig is. -INF valt onder < 0.0.
                if ($price < 0.0 || $price > self::MAX_TRAIN_PRICE_EUROS) {
                    return null;
                }

                return $price;
            },
            is_array($rows) ? $rows : [],
        ), static fn (?float $p): bool => null !== $p);

        if ([] === $prices) {
            return null;
        }

        return Money::fromEuros(min($prices));
    }
}
