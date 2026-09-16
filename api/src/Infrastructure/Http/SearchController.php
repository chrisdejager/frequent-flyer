<?php

declare(strict_types=1);

namespace App\Infrastructure\Http;

use App\Application\SearchFlights\SearchFlightsHandler;
use App\Application\SearchFlights\SearchFlightsQuery;
use App\Domain\Flight\Direction;
use App\Domain\Traveller\UnknownTraveller;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

#[AsController]
final readonly class SearchController
{
    public function __construct(
        private SearchFlightsHandler $handler,
        private SearchResultPresenter $presenter,
    ) {
    }

    #[Route('/api/search', methods: ['GET'])]
    public function __invoke(Request $request): JsonResponse
    {
        $rawDate = (string) $request->query->get('date', '');
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $rawDate);

        if (false === $date) {
            return new JsonResponse(['error' => 'Geef een geldige datum als YYYY-MM-DD.'], 400);
        }

        $direction = Direction::tryFrom((string) $request->query->get('direction', ''));

        if (null === $direction) {
            return new JsonResponse(['error' => 'Richting moet "nl-fi" of "fi-nl" zijn.'], 400);
        }

        try {
            $result = ($this->handler)(new SearchFlightsQuery(
                $date,
                $direction,
                (string) $request->query->get('traveller', ''),
            ));
        } catch (UnknownTraveller $e) {
            return new JsonResponse(['error' => $e->getMessage()], 400);
        }

        return new JsonResponse($this->presenter->present($result));
    }
}
