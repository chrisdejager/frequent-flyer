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
 * Only retrieves the link on a click on "Book": ignav charges one call per
 * request, not in advance for every option (docs/plans/booking-link).
 */
#[AsController]
final readonly class BookingLinkController
{
    /** Format of an ignav_id, as measured in search responses. \z because $ allows a trailing newline. */
    private const REF_PATTERN = '/^[0-9a-f]{32}\z/';

    public function __construct(private BookingLinkSource $source)
    {
    }

    #[Route('/api/booking-link', methods: ['GET'])]
    public function __invoke(Request $request): JsonResponse
    {
        // all() instead of getString(): ?ref[]=… would throw an exception there.
        $ref = $request->query->all()['ref'] ?? null;

        // Every request to ignav costs credits; a ref that cannot be an ignav_id
        // doesn't get through.
        if (!is_string($ref) || 1 !== preg_match(self::REF_PATTERN, $ref)) {
            return new JsonResponse(['error' => 'Invalid or missing ref.'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $link = $this->source->linkFor($ref);
        } catch (BookingLinkUnavailable) {
            // The source failed, not the request: 502 so the tab shows a message.
            return new JsonResponse(['error' => 'The booking link could not be retrieved.'], Response::HTTP_BAD_GATEWAY);
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
