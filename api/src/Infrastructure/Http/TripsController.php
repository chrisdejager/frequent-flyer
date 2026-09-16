<?php

declare(strict_types=1);

namespace App\Infrastructure\Http;

use App\Domain\Trip\PlannedTrip;
use App\Domain\Trip\PlannedTrips;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

#[AsController]
final readonly class TripsController
{
    public function __construct(private PlannedTrips $trips)
    {
    }

    #[Route('/api/trips', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        return new JsonResponse([
            'trips' => array_map(
                static fn (PlannedTrip $t): array => [
                    'date' => $t->date->format('Y-m-d'),
                    'direction' => $t->direction->value,
                    'traveller' => $t->travellerId,
                ],
                $this->trips->all(),
            ),
        ]);
    }
}
