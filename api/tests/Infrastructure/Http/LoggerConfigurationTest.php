<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Http;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpKernel\Log\Logger;

/**
 * Finding 4: the services.yaml logger override sets the threshold to 'warning'.
 * Without that block the default logger silently swallows every warning() — all
 * warnings added to this branch become invisible.
 *
 * Two assertions:
 * 1. The logger service is a Logger instance (class check).
 * 2. The configured threshold is 'warning' (minLevelIndex=3 via reflection).
 *    The default Logger without override has minLevelIndex=4 (error),
 *    causing $logger->warning() to silently do nothing.
 *
 * This test pins the wiring: it fails if the logger block in services.yaml
 * disappears or the threshold moves back to 'error'.
 */
final class LoggerConfigurationTest extends WebTestCase
{
    public function test_logger_service_is_the_configured_warning_level_logger(): void
    {
        static::createClient(); // boot the kernel

        $logger = static::getContainer()->get('test.logger');

        self::assertInstanceOf(
            Logger::class,
            $logger,
            'The logger service must be a Symfony\Component\HttpKernel\Log\Logger. '
            . 'If this fails, the logger: block in services.yaml has disappeared or been replaced, '
            . 'causing all warning() calls to be silently ignored.',
        );

        // The threshold 'warning' gives minLevelIndex=3; the default without override
        // is 'error' (minLevelIndex=4). We test via reflection so the test fails
        // if the logger block in services.yaml disappears or the threshold changes.
        $reflection = new \ReflectionClass($logger);
        $prop = $reflection->getProperty('minLevelIndex');
        $prop->setAccessible(true);
        $minLevelIndex = $prop->getValue($logger);

        self::assertSame(3, $minLevelIndex,
            'The logger threshold must be "warning" (minLevelIndex=3). '
            . 'The default Logger without override has minLevelIndex=4 (error), '
            . 'causing warning() calls to be silently ignored. '
            . 'Check the logger: block in config/services.yaml.',
        );
    }
}
