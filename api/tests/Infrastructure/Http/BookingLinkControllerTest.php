<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Http;

use App\Domain\Booking\BookingLink;
use App\Domain\Port\BookingLinkSource;
use App\Tests\Double\FixedBookingLinkSource;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class BookingLinkControllerTest extends WebTestCase
{
    private const REF = '0ea5aa41342f00000056346ef96d746d';

    public function test_a_direct_link_is_returned_as_found(): void
    {
        $client = static::createClient();

        $source = new FixedBookingLinkSource(
            BookingLink::direct('https://www.klm.nl/boeken?flight=KL1167', 'KLM'),
        );
        static::getContainer()->set(BookingLinkSource::class, $source);

        $client->request('GET', '/api/booking-link?ref='.self::REF);

        self::assertResponseIsSuccessful();

        $body = json_decode($client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(
            ['status' => 'found', 'url' => 'https://www.klm.nl/boeken?flight=KL1167', 'provider' => 'KLM'],
            $body,
        );
        // De ref uit de querystring komt ongewijzigd bij de bron aan.
        self::assertSame([self::REF], $source->calls);
    }

    public function test_no_direct_link_is_returned_as_such(): void
    {
        $client = static::createClient();

        static::getContainer()->set(
            BookingLinkSource::class,
            new FixedBookingLinkSource(BookingLink::noDirectLink()),
        );

        $client->request('GET', '/api/booking-link?ref='.self::REF);

        self::assertResponseIsSuccessful();

        $body = json_decode($client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(['status' => 'no_direct_link'], $body);
    }
}
