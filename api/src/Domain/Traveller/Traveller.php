<?php

declare(strict_types=1);

namespace App\Domain\Traveller;

use App\Domain\Flight\Carrier;

final readonly class Traveller
{
    /**
     * @param array<string, LoyaltyStatus> $loyalty gesleuteld op Carrier-waarde
     */
    private function __construct(
        public string $id,
        public string $name,
        private array $loyalty,
    ) {
    }

    public static function chris(): self
    {
        return new self('chris', 'Chris', [
            Carrier::Finnair->value => LoyaltyStatus::Silver,
            Carrier::Sas->value => LoyaltyStatus::Silver,
        ]);
    }

    public static function partner(): self
    {
        return new self('partner', 'Partner', [
            Carrier::Sas->value => LoyaltyStatus::Silver,
        ]);
    }

    public static function byId(string $id): self
    {
        return match ($id) {
            'chris' => self::chris(),
            'partner' => self::partner(),
            default => throw UnknownTraveller::withId($id),
        };
    }

    public function statusFor(Carrier $carrier): LoyaltyStatus
    {
        return $this->loyalty[$carrier->value] ?? LoyaltyStatus::None;
    }
}
