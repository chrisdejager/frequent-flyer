<?php

declare(strict_types=1);

namespace App\Domain\Traveller;

final readonly class Traveller
{
    private function __construct(
        public string $id,
        public string $name,
    ) {
    }

    public static function chris(): self
    {
        return new self('chris', 'Chris');
    }

    public static function partner(): self
    {
        return new self('partner', 'Partner');
    }

    public static function byId(string $id): self
    {
        return match ($id) {
            'chris' => self::chris(),
            'partner' => self::partner(),
            default => throw UnknownTraveller::withId($id),
        };
    }
}
