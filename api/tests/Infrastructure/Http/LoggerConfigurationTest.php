<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Http;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpKernel\Log\Logger;

/**
 * Bevinding 4: de services.yaml-logger-override zet de drempelwaarde op 'warning'.
 * Zonder dat blok slokt de standaardlogger elke warning() stil in — alle
 * waarschuwingen die deze branch heeft toegevoegd worden dan onzichtbaar.
 *
 * Twee asserts:
 * 1. De logger-service is een Logger-instantie (class check).
 * 2. De geconfigureerde drempelwaarde is 'warning' (minLevelIndex=3 via reflectie).
 *    De standaard Logger zonder override heeft minLevelIndex=4 (error),
 *    waardoor $logger->warning() stil niets doet.
 *
 * Deze test pinnt de bedrading: hij faalt als het logger-blok in services.yaml
 * verdwijnt of de drempelwaarde naar 'error' teruggaat.
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
            'De logger-service moet een Symfony\Component\HttpKernel\Log\Logger zijn. '
            . 'Als dit faalt, is het logger:-blok in services.yaml verdwenen of vervangen, '
            . 'waardoor alle warning()-aanroepen stilletjes worden genegeerd.',
        );

        // De drempelwaarde 'warning' geeft minLevelIndex=3; de standaard zonder override
        // is 'error' (minLevelIndex=4). We toetsen via reflectie zodat de test faalt
        // als het logger-blok in services.yaml verdwijnt of de drempelwaarde verandert.
        $reflection = new \ReflectionClass($logger);
        $prop = $reflection->getProperty('minLevelIndex');
        $prop->setAccessible(true);
        $minLevelIndex = $prop->getValue($logger);

        self::assertSame(3, $minLevelIndex,
            'De logger-drempelwaarde moet "warning" (minLevelIndex=3) zijn. '
            . 'De standaard Logger zonder override heeft minLevelIndex=4 (error), '
            . 'waardoor warning()-aanroepen stil worden genegeerd. '
            . 'Controleer het logger:-blok in config/services.yaml.',
        );
    }
}
