<?php

declare(strict_types=1);

namespace App\Application\SearchFlights;

use App\Domain\Traveller\Traveller;

final readonly class SearchResult
{
    /**
     * @param PricedItinerary[] $options goedkoopste eerst
     */
    public function __construct(
        public array $options,
        public Traveller $traveller,
        public \DateTimeImmutable $searchedAt,
    ) {
    }
}
