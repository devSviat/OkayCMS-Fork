<?php

namespace Admin;

use PHPUnit\Framework\TestCase;

/**
 * Підказки адмінки виводяться без екранування, тож незакритий або подвоєний
 * <p> у перекладі ламає розмітку навколишнього блоку.
 */
class BackendHintMarkupTest extends TestCase
{
    public function testParagraphsAreBalancedAndNotNested(): void
    {
        $broken = [];
        foreach (['ua', 'en', 'ru'] as $language) {
            foreach ($this->load($language) as $key => $value) {
                $opened = preg_match_all('~<p[\s>]~', $value);
                $closed = substr_count($value, '</p>');
                if ($opened !== $closed || str_contains($value, '<p><p>') || str_contains($value, '</p></p>')) {
                    $broken[] = "{$language}: {$key}";
                }
            }
        }

        $this->assertSame([], $broken);
    }

    /** @return array<string, string> */
    private function load(string $language): array
    {
        $lang = [];
        include dirname(__DIR__, 2) . "/backend/lang/{$language}.php";

        return $lang;
    }
}
