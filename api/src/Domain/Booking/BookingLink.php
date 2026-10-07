<?php

declare(strict_types=1);

namespace App\Domain\Booking;

/**
 * Result of a booking link request: a direct link to the carrier
 * or the determination that none is available.
 */
final readonly class BookingLink
{
    private function __construct(public ?string $url, public ?string $provider)
    {
    }

    public static function direct(string $url, string $provider): self
    {
        // The URL goes unseen to location.replace in the browser; only HTTPS
        // excludes javascript:- and data:-URLs.
        if (!str_starts_with($url, 'https://')) {
            throw new \InvalidArgumentException('A booking link must be HTTPS.');
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
