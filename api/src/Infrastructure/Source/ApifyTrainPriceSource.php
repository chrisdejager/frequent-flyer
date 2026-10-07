<?php

declare(strict_types=1);

namespace App\Infrastructure\Source;

use App\Domain\Flight\Money;
use App\Domain\Port\TrainPriceSource;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class ApifyTrainPriceSource implements TrainPriceSource
{
    private const ACTOR = 'studio-amba~vr-scraper';
    private const TTL_SECONDS = 86400;
    private const TTL_NULL_SECONDS = 900; // 15 minutes: cache failures to save Apify credits

    /**
     * Upper limit for a train ticket HEL–TKU in euros.
     * A VR ticket costs tens of euros; anything above is a scraper artifact.
     * Without this limit, (int) round($euros * 100) overflows to a negative
     * number, upon which Money throws InvalidArgumentException — HTTP 500.
     */
    private const MAX_TRAIN_PRICE_EUROS = 10_000.0;

    /**
     * Sentinel value in the cache when the scraper returned nothing usable.
     * Distinguishes "cached null" from "price is 0 cents" and from "cache miss".
     */
    private const CACHE_NULL_SENTINEL = 'null';

    public function __construct(
        private HttpClientInterface $http,
        private CacheItemPoolInterface $cache,
        private string $apiToken,
        private ?LoggerInterface $logger = null,
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
        try {
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
        } catch (ExceptionInterface $e) {
            $this->logger?->warning('Apify HTTP error fetching train price ({date}): {reason}', [
                'date'   => $date->format('Y-m-d'),
                'reason' => $e->getMessage(),
            ]);

            return null;
        } catch (\JsonException $e) {
            $this->logger?->warning('Apify returned unreadable JSON for train price ({date}): {reason}', [
                'date'   => $date->format('Y-m-d'),
                'reason' => $e->getMessage(),
            ]);

            return null;
        }

        $prices = array_filter(array_map(
            static function (mixed $row): ?float {
                if (!is_array($row)) {
                    return null;
                }

                $raw = $row['priceEur'] ?? null;

                // Filter unusable prices before the float cast:
                // - non-numeric (N/A, empty string, regular strings) → null
                // - absurdly high (incl. INF, because INF > MAX_TRAIN_PRICE_EUROS is true) → null
                // - negative → null (a negative train price is not usable; -INF falls here too)
                // Price 0.0 is valid: a free ride is possible.
                if (!is_numeric($raw)) {
                    return null;
                }

                $price = (float) $raw;

                // The upper limit also catches INF (INF > MAX_TRAIN_PRICE_EUROS is true),
                // making a separate is_finite() check unnecessary. -INF falls under < 0.0.
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
