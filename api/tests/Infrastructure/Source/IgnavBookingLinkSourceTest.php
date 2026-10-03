<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Source;

use App\Infrastructure\Source\IgnavBookingLinkSource;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class IgnavBookingLinkSourceTest extends TestCase
{
    private const REF = '0ea5aa41342f00000056346ef96d746d';

    // ---------------------------------------------------------------- helpers

    /**
     * @param array<string, mixed> $payload
     */
    private function sourceReturning(array $payload, ?callable $inspect = null): IgnavBookingLinkSource
    {
        $client = new MockHttpClient(function (string $method, string $url, array $options) use ($payload, $inspect): MockResponse {
            $inspect?->__invoke($method, $url, $options);

            return new MockResponse(json_encode($payload, JSON_THROW_ON_ERROR));
        });

        return new IgnavBookingLinkSource($client, 'test-key');
    }

    /**
     * De échte respons van ignav booking-links, gemeten op 3 okt 2026 voor AY1302
     * AMS→HEL: één Finnair-link (airline, € 143) en één lastminute.com-link
     * (third_party, € 199).
     *
     * @return array<string, mixed>
     */
    private function realPayload(): array
    {
        return json_decode(
            (string) file_get_contents(__DIR__.'/fixtures/ignav-booking-links.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function link(string $provider, string $type, float $amount, string $url): array
    {
        return [
            'provider_name' => $provider,
            'provider_type' => $type,
            'price' => ['amount' => $amount, 'currency' => 'EUR', 'status' => 'verified'],
            'url' => $url,
        ];
    }

    // ---------------------------------------------------------------- tests

    public function test_it_posts_only_the_ignav_id_with_the_api_key(): void
    {
        $seen = [];
        $source = $this->sourceReturning(
            $this->realPayload(),
            function (string $method, string $url, array $options) use (&$seen): void {
                $seen = ['method' => $method, 'url' => $url, 'options' => $options];
            },
        );

        $source->linkFor(self::REF);

        self::assertSame('POST', $seen['method']);
        self::assertSame('https://ignav.com/api/fares/booking-links', $seen['url']);

        $flatHeaders = [];
        array_walk_recursive(
            $seen['options']['headers'],
            static function ($value) use (&$flatHeaders): void { $flatHeaders[] = $value; },
        );
        self::assertContains('X-Api-Key: test-key', $flatHeaders);

        // Gemeten: met een 'market' erbij geeft ignav 400 conflicting_booking_lookup_mode.
        $body = json_decode((string) $seen['options']['body'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['ignav_id' => self::REF], $body);
    }

    public function test_it_returns_the_airlines_own_link_from_the_measured_response(): void
    {
        $link = $this->sourceReturning($this->realPayload())->linkFor(self::REF);

        self::assertTrue($link->isDirect());
        self::assertSame('Finnair', $link->provider);
        self::assertStringStartsWith('https://www.finnair.com/nl-en/booking/flight-selection?', (string) $link->url);
        self::assertStringContainsString('departureFlightNumbers=AY1302', (string) $link->url);
    }

    public function test_an_ota_listed_first_does_not_win_over_the_airline(): void
    {
        // Volgorde omgedraaid t.o.v. de meting: "neem de eerste link" moet hier falen.
        $payload = $this->realPayload();
        $payload['booking_options'][0]['links'] = array_reverse($payload['booking_options'][0]['links']);

        $link = $this->sourceReturning($payload)->linkFor(self::REF);

        self::assertSame('Finnair', $link->provider);
    }

    public function test_the_cheapest_of_several_direct_links_wins(): void
    {
        $payload = ['booking_options' => [
            ['legs' => ['outbound'], 'links' => [
                self::link('KLM', 'airline', 302.0, 'https://www.klm.nl/duur'),
                self::link('Budget', 'third_party', 150.0, 'https://ota.example/goedkoop'),
            ]],
            ['legs' => ['outbound'], 'links' => [
                self::link('KLM', 'airline', 273.0, 'https://www.klm.nl/goedkoop'),
            ]],
        ]];

        $link = $this->sourceReturning($payload)->linkFor(self::REF);

        self::assertSame('https://www.klm.nl/goedkoop', $link->url);
    }
    // ------------------------------------------------------ geen directe link

    /**
     * Gemeten 3 okt 2026 voor SK556+SK2728 op 26 nov: SAS levert alleen
     * http://www.flysas.com/nl/nl/ zonder prijs, KLM een codeshare-deeplink mét prijs.
     *
     * @return array<string, mixed>
     */
    private function sasPayload(): array
    {
        return json_decode(
            (string) file_get_contents(__DIR__.'/fixtures/ignav-booking-links-sas.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
    }

    public function test_the_measured_sas_http_homepage_loses_to_the_klm_deeplink(): void
    {
        $link = $this->sourceReturning($this->sasPayload())->linkFor(self::REF);

        self::assertSame('KLM', $link->provider);
        self::assertStringStartsWith('https://www.klm.nl/', (string) $link->url);
    }

    public function test_the_sas_http_homepage_alone_is_no_direct_link(): void
    {
        $payload = $this->sasPayload();
        $payload['booking_options'][0]['links'] = array_values(array_filter(
            $payload['booking_options'][0]['links'],
            static fn (array $l): bool => 'KLM' !== $l['provider_name'],
        ));

        $link = $this->sourceReturning($payload)->linkFor(self::REF);

        self::assertFalse($link->isDirect());
    }

    public function test_ota_links_alone_give_no_direct_link(): void
    {
        $payload = $this->realPayload();
        $payload['booking_options'][0]['links'] = array_values(array_filter(
            $payload['booking_options'][0]['links'],
            static fn (array $l): bool => 'airline' !== $l['provider_type'],
        ));

        $link = $this->sourceReturning($payload)->linkFor(self::REF);

        self::assertFalse($link->isDirect());
        self::assertNull($link->url);
    }

    public function test_no_booking_options_at_all_is_no_direct_link(): void
    {
        $link = $this->sourceReturning(['booking_options' => []])->linkFor(self::REF);

        self::assertFalse($link->isDirect());
    }

    public function test_a_javascript_url_is_never_used(): void
    {
        $payload = ['booking_options' => [['legs' => ['outbound'], 'links' => [
            self::link('Finnair', 'airline', 1.0, 'javascript:alert(1)'),
        ]]]];

        $link = $this->sourceReturning($payload)->linkFor(self::REF);

        self::assertFalse($link->isDirect());
    }
}
