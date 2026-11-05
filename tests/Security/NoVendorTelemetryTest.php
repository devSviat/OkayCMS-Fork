<?php

namespace Security;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Ядро не звертається до серверів вендора і сторонніх трекерів: ні перевірки
 * ліцензій, ні маркетплейсу, ні каналу підтримки, ні GTM в адмінці.
 * Апстрім повертає ці виклики при кожному синку, тож тест ловить їх у диффі.
 */
class NoVendorTelemetryTest extends TestCase
{
    private const array FORBIDDEN = [
        'okay-cms.support',
        'modules.okay-cms.com',
        'okay-cms.com/last_version',
        'api/v2/modules/access',
        'marketplace_url',
        'GTM-P6T2LJP',
        'googletagmanager.com',
        'ipinfo.io/ip',
    ];

    private const array SCANNED = [
        'Okay/Core',
        'Okay/Controllers',
        'Okay/Entities',
        'backend/Controllers',
        'backend/Helpers',
        'backend/design/html',
        'config',
        'index.php',
        'backend/index.php',
    ];

    public function testCoreHasNoVendorEndpoints(): void
    {
        $offenders = [];
        foreach ($this->files() as $relative => $source) {
            foreach (self::FORBIDDEN as $needle) {
                if (stripos($source, $needle) !== false) {
                    $offenders[] = "$relative: $needle";
                }
            }
        }

        $this->assertSame([], $offenders);
    }

    public function testSupportEndpointIsNotRouted(): void
    {
        $routes = (string) file_get_contents(self::root() . '/Okay/Core/config/routes.php');

        $this->assertStringNotContainsString("'SupportController'", $routes);
        $this->assertStringNotContainsString("'/support.php'", $routes);
        $this->assertFileDoesNotExist(self::root() . '/Okay/Controllers/SupportController.php');
    }

    /** @return iterable<string, string> */
    private function files(): iterable
    {
        foreach (self::SCANNED as $path) {
            $absolute = self::root() . '/' . $path;
            if (is_file($absolute)) {
                yield $path => (string) file_get_contents($absolute);
                continue;
            }

            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($absolute, RecursiveDirectoryIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if (!in_array($file->getExtension(), ['php', 'tpl', 'ini', 'js'], true)) {
                    continue;
                }
                yield substr($file->getPathname(), strlen(self::root()) + 1) => (string) file_get_contents($file->getPathname());
            }
        }
    }

    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }
}
