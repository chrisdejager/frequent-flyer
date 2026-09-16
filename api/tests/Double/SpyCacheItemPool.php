<?php

declare(strict_types=1);

namespace App\Tests\Double;

use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;

/**
 * In-memory CacheItemPool that records the TTL (seconds) passed to
 * CacheItemInterface::expiresAfter() for each saved item.
 *
 * Recorded TTLs are available via $pool->recordedTtls[$key].
 *
 * This double is intentionally framework-free: it only uses PSR-6 interfaces.
 */
final class SpyCacheItemPool implements CacheItemPoolInterface
{
    /** @var array<string, SpyCacheItem> */
    private array $items = [];

    /** @var array<string, int|null> Keyed by cache key, value is the seconds passed to expiresAfter(). */
    public array $recordedTtls = [];

    public function getItem(string $key): SpyCacheItem
    {
        if (!isset($this->items[$key])) {
            return new SpyCacheItem($key, null, false);
        }

        $stored = $this->items[$key];

        // Return a fresh hit-item with the stored value so the pool stays
        // consistent across calls, while each getItem() call gets a new object.
        return new SpyCacheItem($key, $stored->get(), true);
    }

    public function getItems(array $keys = []): iterable
    {
        foreach ($keys as $key) {
            yield $key => $this->getItem($key);
        }
    }

    public function hasItem(string $key): bool
    {
        return isset($this->items[$key]);
    }

    public function clear(): bool
    {
        $this->items = [];
        $this->recordedTtls = [];

        return true;
    }

    public function deleteItem(string $key): bool
    {
        unset($this->items[$key], $this->recordedTtls[$key]);

        return true;
    }

    public function deleteItems(array $keys): bool
    {
        foreach ($keys as $key) {
            $this->deleteItem($key);
        }

        return true;
    }

    public function save(CacheItemInterface $item): bool
    {
        $key = $item->getKey();

        if ($item instanceof SpyCacheItem) {
            $this->recordedTtls[$key] = $item->getRecordedTtl();
        }

        $this->items[$key] = new SpyCacheItem($key, $item->get(), true);

        return true;
    }

    public function saveDeferred(CacheItemInterface $item): bool
    {
        return $this->save($item);
    }

    public function commit(): bool
    {
        return true;
    }
}
