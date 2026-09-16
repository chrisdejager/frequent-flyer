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

        $item->set($price->cents);
        $item->expiresAfter(self::TTL_SECONDS);
        $this->cache->save($item);

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
            static fn (array $row): ?float => isset($row['priceEur']) ? (float) $row['priceEur'] : null,
            is_array($rows) ? $rows : [],
        ));

        if ([] === $prices) {
            return Money::zero();
        }

        return Money::fromEuros(min($prices));
    }
}
