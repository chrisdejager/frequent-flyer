<?php

declare(strict_types=1);

namespace App\Domain\Flight;

final readonly class Itinerary
{
    /**
     * @param Segment[] $segments
     */
    public function __construct(
        public Carrier $carrier,
        public array $segments,
        public Money $basePrice,
        public RoutePattern $pattern,
        /** Reference from the source to retrieve a booking link later (ignav_id). */
        public ?string $bookingRef = null,
        /** When the source fetched this price; null = unknown. Not the airline's quote time. */
        public ?\DateTimeImmutable $observedAt = null,
    ) {
        if ([] === $segments) {
            throw new \InvalidArgumentException('A travel option must have at least one segment.');
        }

        if (count($segments) > 2) {
            throw new \InvalidArgumentException('More than one connection is not supported.');
        }
    }

    public function origin(): Airport
    {
        return $this->segments[0]->from;
    }

    public function destination(): Airport
    {
        return $this->segments[count($this->segments) - 1]->to;
    }

    public function departAt(): \DateTimeImmutable
    {
        return $this->segments[0]->departAt;
    }

    public function arriveAt(): \DateTimeImmutable
    {
        return $this->segments[count($this->segments) - 1]->arriveAt;
    }

    public function stopCount(): int
    {
        return count($this->segments) - 1;
    }

    public function connectionMinutes(): ?int
    {
        if (1 !== $this->stopCount()) {
            return null;
        }

        return intdiv(
            $this->segments[1]->departAt->getTimestamp() - $this->segments[0]->arriveAt->getTimestamp(),
            60,
        );
    }

    public function connectsVia(): ?Airport
    {
        return 1 === $this->stopCount() ? $this->segments[0]->to : null;
    }
}
