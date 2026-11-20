<?php

namespace Security;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * {$smarty.get.*} і подібні — це вміст адресного рядка, який підсовує той, хто дав
 * посилання. Автоекранування в адмінці немає, тож друк без модифікатора — reflected XSS
 * у сесії менеджера.
 *
 * Виняток — {$smarty.get.return}: його ~40 шаблонів ядра й модулів друкують сирим, а
 * backend/index.php чистить параметр до контролерів (BackendReturnUrlTest стереже і
 * санітайзер, і те, що він стоїть перед контролерами).
 *
 * Присвоєння ({$x = $smarty.get.y}, {assign}) тест не простежує: друк такої змінної — на ревʼю.
 */
class SuperglobalPrintEscapingTest extends TestCase
{
    private const string SUPERGLOBAL = '/\$smarty\.(?:get|request|server|post|cookies)\b/';
    private const string SAFE_MODIFIER = '/\|\s*(?:escape|intval)\b/';
    private const string SANITIZED_ON_ENTRY = '/^\{\$smarty\.get\.return\}$/';

    #[DataProvider('templateProvider')]
    public function testSuperglobalsArePrintedEscaped(string $file): void
    {
        $source = file_get_contents($file);
        self::assertIsString($source);

        self::assertSame([], self::rawPrints($source), self::relative($file) . ' друкує $smarty.get/request/server без |escape');
    }

    public function testScannerSeesRawPrint(): void
    {
        $template = <<<'TPL'
            {* {$smarty.get.commented} *}
            {if $smarty.get.return}<a href="{$smarty.get.return}">{$smarty.get.return|default:''}
            {$smarty.request.q|default:''}
            {$smarty.server.REQUEST_URI|escape}{$smarty.get.id|intval}
            {url controller=X return=$smarty.server.REQUEST_URI}
            {$x = $smarty.get.y}
            TPL;

        self::assertSame(
            ["2: {\$smarty.get.return|default:''}", "3: {\$smarty.request.q|default:''}"],
            self::rawPrints($template)
        );
    }

    /** @return array<string, array{string}> */
    public static function templateProvider(): array
    {
        $root = dirname(__DIR__, 2);
        $cases = [];

        foreach (['backend/design/html', 'design', 'Okay/Modules'] as $dir) {
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $dir, RecursiveDirectoryIterator::SKIP_DOTS));
            foreach ($files as $file) {
                $path = $file->getPathname();
                if ($file->getExtension() !== 'tpl' || str_contains($path, '/compiled/')) {
                    continue;
                }
                if (preg_match(self::SUPERGLOBAL, (string) file_get_contents($path))) {
                    $cases[self::relative($path)] = [$path];
                }
            }
        }

        ksort($cases);

        return $cases;
    }

    /** @return list<string> «рядок: тег» для кожного друку суперглобалу без екранування */
    private static function rawPrints(string $source): array
    {
        // Коментар замінюється пробілами того ж розміру, щоб номери рядків не зсунулись.
        $source = preg_replace_callback('/\{\*.*?\*\}/s', static fn(array $m) => preg_replace('/\S/', ' ', $m[0]), $source);

        preg_match_all('/\{\$(?:[^{}]|\{[^{}]*\})*\}/', $source, $matches, PREG_OFFSET_CAPTURE);

        $raw = [];
        foreach ($matches[0] as [$tag, $offset]) {
            $isAssignment = preg_match('/^\{\$[\w.\[\]$>-]+\s*=(?!=)/', $tag) === 1;
            if ($isAssignment || !preg_match(self::SUPERGLOBAL, $tag) || preg_match(self::SAFE_MODIFIER, $tag)
                || preg_match(self::SANITIZED_ON_ENTRY, $tag)) {
                continue;
            }
            $raw[] = (substr_count($source, "\n", 0, $offset) + 1) . ': ' . preg_replace('/\s+/', ' ', $tag);
        }

        return $raw;
    }

    private static function relative(string $path): string
    {
        return str_replace(dirname(__DIR__, 2) . '/', '', $path);
    }
}
