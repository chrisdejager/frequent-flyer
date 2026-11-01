<?php

declare(strict_types=1);

namespace App\Domain\Traveller;

/**
 * Who the prices are for. The demo has a single traveller: without baggage
 * costs (ADR-0005) the traveller does not change a price. The API still takes
 * an id, so per-traveller benefits can come back without an API change; real
 * travellers arrive with users (roadmap phase 1).
 */
final readonly class Traveller
{
    private function __construct(
        public string $id,
        public string $name,
    ) {
    }

    public static function default(): self
    {
        return new self('default', 'Traveller');
    }

    public static function byId(string $id): self
    {
        return match ($id) {
            'default' => self::default(),
            default => throw UnknownTraveller::withId($id),
        };
    }
}
