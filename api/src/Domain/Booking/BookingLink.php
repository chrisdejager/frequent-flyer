<?php

declare(strict_types=1);

namespace App\Domain\Booking;

/**
 * Uitkomst van een boekingslink-aanvraag: een directe link naar de maatschappij
 * zelf, of de vaststelling dat er geen is.
 */
final readonly class BookingLink
{
    private function __construct(public ?string $url, public ?string $provider)
    {
    }

    public static function direct(string $url, string $provider): self
    {
        // De url gaat ongezien naar location.replace in de browser; alleen https
        // sluit javascript:- en data:-urls uit.
        if (!str_starts_with($url, 'https://')) {
            throw new \InvalidArgumentException('Een boekingslink moet https zijn.');
        }

        return new self($url, $provider);
    }

    public static function noDirectLink(): self
    {
        return new self(null, null);
    }

    public function isDirect(): bool
    {
        return null !== $this->url;
    }
}
