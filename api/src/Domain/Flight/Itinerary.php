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
        /** Verwijzing van de bron om later een boekingslink op te vragen (ignav_id). */
        public ?string $bookingRef = null,
    ) {
        if ([] === $segments) {
            throw new \InvalidArgumentException('Een reisoptie heeft minstens één segment.');
        }

        if (count($segments) > 2) {
            throw new \InvalidArgumentException('Meer dan één overstap wordt niet ondersteund.');
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
