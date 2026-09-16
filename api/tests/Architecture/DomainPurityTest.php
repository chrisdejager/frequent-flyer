<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use PHPUnit\Framework\TestCase;

final class DomainPurityTest extends TestCase
{
    private const FORBIDDEN = ['Symfony\\', 'Doctrine\\', 'Psr\\', 'Twig\\'];

    public function test_the_domain_layer_imports_no_framework_code(): void
    {
        $offenders = [];

        foreach ($this->domainFiles() as $file) {
            $source = file_get_contents($file);

            foreach (self::FORBIDDEN as $prefix) {
                if (str_contains($source, $prefix)) {
                    $offenders[] = sprintf('%s bevat verwijzing naar %s', basename($file), $prefix);
                }
            }
        }

        self::assertSame([], $offenders, implode("\n", $offenders));
    }

    public function test_every_domain_file_is_strictly_typed(): void
    {
        $offenders = [];

        foreach ($this->domainFiles() as $file) {
            if (!str_contains(file_get_contents($file), 'declare(strict_types=1)')) {
                $offenders[] = basename($file);
            }
        }

        self::assertSame([], $offenders, 'ontbrekende strict_types: '.implode(', ', $offenders));
    }

    public function test_the_domain_directory_is_not_empty(): void
    {
        self::assertNotEmpty($this->domainFiles(), 'de test zou anders altijd slagen');
    }

    /**
     * @return string[]
     */
    private function domainFiles(): array
    {
        $files = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(__DIR__.'/../../src/Domain'),
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && 'php' === $file->getExtension()) {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
