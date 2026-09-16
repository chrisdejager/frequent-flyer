<?php

declare(strict_types=1);

namespace App\Tests\Double;

use Psr\Cache\CacheItemInterface;

/**
 * Simple PSR-6 cache item that records the value passed to expiresAfter().
 *
 * Intentionally framework-free: only Psr\Cache\CacheItemInterface is used.
 */
final class SpyCacheItem implements CacheItemInterface
{
    private mixed $value;
    private ?int $recordedTtl = null;

    public function __construct(
        private readonly string $key,
        mixed $value,
        private bool $isHit,
    ) {
        $this->value = $value;
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function get(): mixed
    {
        return $this->isHit ? $this->value : null;
    }

    public function isHit(): bool
    {
        return $this->isHit;
    }

    public function set(mixed $value): static
    {
        $this->value = $value;

        return $this;
    }

    public function expiresAt(?\DateTimeInterface $expiration): static
    {
        // Not used by ApifyTrainPriceSource; no-op is sufficient.
        return $this;
    }

    public function expiresAfter(int|\DateInterval|null $time): static
    {
        if ($time instanceof \DateInterval) {
            // Convert DateInterval to seconds for uniform recording.
            $now = new \DateTimeImmutable();
            $this->recordedTtl = (int) $now->add($time)->format('U') - (int) $now->format('U');
        } else {
            $this->recordedTtl = $time;
        }

        return $this;
    }

    /**
     * Returns the TTL in seconds that was passed to expiresAfter(), or null if
     * expiresAfter() was never called on this item.
     */
    public function getRecordedTtl(): ?int
    {
        return $this->recordedTtl;
    }
}
