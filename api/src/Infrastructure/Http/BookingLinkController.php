<?php

declare(strict_types=1);

namespace App\Infrastructure\Http;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Tracer bullet van de boekingslink-feature (docs/plans/boekingslink).
 * Slice 2 vervangt deze vaste waarde door BookingLinkSource / ignav.
 */
#[AsController]
final readonly class BookingLinkController
{
    #[Route('/api/booking-link', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        return new JsonResponse([
            'status' => 'found',
            'url' => 'https://www.finnair.com',
            'provider' => 'Finnair',
        ]);
    }
}
