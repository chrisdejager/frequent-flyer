<?php

declare(strict_types=1);

namespace App\Infrastructure\Http;

use App\Domain\Port\BookingLinkSource;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Haalt pas bij een klik op "Boeken" de link op: ignav rekent één call per
 * aanvraag, dus niet bij voorbaat voor elke optie (docs/plans/boekingslink).
 */
#[AsController]
final readonly class BookingLinkController
{
    public function __construct(private BookingLinkSource $source)
    {
    }

    #[Route('/api/booking-link', methods: ['GET'])]
    public function __invoke(Request $request): JsonResponse
    {
        $link = $this->source->linkFor($request->query->getString('ref'));

        if (!$link->isDirect()) {
            return new JsonResponse(['status' => 'no_direct_link']);
        }

        return new JsonResponse([
            'status' => 'found',
            'url' => $link->url,
            'provider' => $link->provider,
        ]);
    }
}
