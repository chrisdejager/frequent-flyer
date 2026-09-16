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

    public function __construct(
        private HttpClientInterface $http,
        private CacheItemPoolInterface $cache,
        private string $apiToken,
    ) {
    }

    public function priceFor(\DateTimeImmutable $date): Money
    {
        $item = $this->cache->getItem('vr.hel-tku.'.$date->format('Y-m-d'));

        if ($item->isHit()) {
            return Money::fromCents((int) $item->get());
        }

        $price = $this->fetch($date);

        if (!$price->isZero()) {
            $item->set($price->cents);
            $item->expiresAfter(self::TTL_SECONDS);
            $this->cache->save($item);
        }

        return $price;
    }

    private function fetch(\DateTimeImmutable $date): Money
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
                // - niet-eindig (INF, -INF uit 1e999) → null
                // - negatief → null (een negatieve treinprijs is geen bruikbare prijs)
                // Prijs 0.0 is geldig: een gratis rit is mogelijk.
                if (!is_numeric($raw)) {
                    return null;
                }

                $price = (float) $raw;

                if (!is_finite($price) || $price < 0.0) {
                    return null;
                }

                return $price;
            },
            is_array($rows) ? $rows : [],
        ), static fn (?float $p): bool => null !== $p);

        if ([] === $prices) {
            return Money::zero();
        }

        return Money::fromEuros(min($prices));
    }
}
