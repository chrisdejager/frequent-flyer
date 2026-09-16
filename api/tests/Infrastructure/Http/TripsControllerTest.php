<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Http;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class TripsControllerTest extends WebTestCase
{
    public function test_it_lists_the_two_planned_trips(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/trips');

        self::assertResponseIsSuccessful();

        $body = json_decode($client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame([
            ['date' => '2026-10-25', 'direction' => 'fi-nl', 'traveller' => 'partner'],
            ['date' => '2026-10-29', 'direction' => 'nl-fi', 'traveller' => 'partner'],
        ], $body['trips']);
    }

    public function test_the_trips_are_sorted_by_date(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/trips');

        $body = json_decode($client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $dates = array_column($body['trips'], 'date');

        $sorted = $dates;
        sort($sorted);

        self::assertSame($sorted, $dates);
    }
}
