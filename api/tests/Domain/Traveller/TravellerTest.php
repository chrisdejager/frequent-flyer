<?php

declare(strict_types=1);

namespace App\Tests\Domain\Traveller;

use App\Domain\Traveller\Traveller;
use PHPUnit\Framework\TestCase;

final class TravellerTest extends TestCase
{
    public function test_lookup_by_id(): void
    {
        self::assertSame('chris', Traveller::byId('chris')->id);
        self::assertSame('partner', Traveller::byId('partner')->id);
    }

    public function test_unknown_id_is_rejected(): void
    {
        $this->expectException(\DomainException::class);

        Traveller::byId('iemand-anders');
    }
}
