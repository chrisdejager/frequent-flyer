<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Http;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class BookingLinkControllerTest extends WebTestCase
{
    public function test_the_tracer_stub_returns_a_found_link(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/booking-link?ref=d10d6e8d0da24735b03dc184d0912c3d');

        self::assertResponseIsSuccessful();

        $body = json_decode($client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(
            ['status' => 'found', 'url' => 'https://www.finnair.com', 'provider' => 'Finnair'],
            $body
        );
    }
}
