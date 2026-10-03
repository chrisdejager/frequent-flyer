<?php

declare(strict_types=1);

namespace App\Infrastructure\Http;

use App\Domain\Booking\BookingLinkUnavailable;
use App\Domain\Port\BookingLinkSource;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Haalt pas bij een klik op "Boeken" de link op: ignav rekent één call per
 * aanvraag, dus niet bij voorbaat voor elke optie (docs/plans/boekingslink).
 */
#[AsController]
final readonly class BookingLinkController
{
    /** Vorm van een ignav_id, zoals gemeten in zoekresponsen. \z, want $ laat een afsluitende newline door. */
    private const REF_PATTERN = '/^[0-9a-f]{32}\z/';

    public function __construct(private BookingLinkSource $source)
    {
    }

    #[Route('/api/booking-link', methods: ['GET'])]
    public function __invoke(Request $request): JsonResponse
    {
        // all() in plaats van getString(): ?ref[]=… zou daar een exception geven.
        $ref = $request->query->all()['ref'] ?? null;

        // Elke aanvraag bij ignav kost tegoed; een ref die geen ignav_id kan zijn,
        // komt er niet doorheen.
        if (!is_string($ref) || 1 !== preg_match(self::REF_PATTERN, $ref)) {
            return new JsonResponse(['error' => 'Ongeldige of ontbrekende ref.'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $link = $this->source->linkFor($ref);
        } catch (BookingLinkUnavailable) {
            // De bron faalde, niet de aanvraag: 502, zodat het tabblad een melding toont.
            return new JsonResponse(['error' => 'De boekingslink kon niet worden opgehaald.'], Response::HTTP_BAD_GATEWAY);
        }

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
