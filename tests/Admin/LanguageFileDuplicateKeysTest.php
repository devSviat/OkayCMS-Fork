<?php

namespace Admin;

use PHPUnit\Framework\TestCase;

/**
 * Повторне присвоєння ключа в мовному файлі мовчки перекриває перше, тож
 * виправлення першого рядка ні на що не впливає. Так у ua.php роками лежали
 * мертві дублікати, які правили замість справжніх.
 */
class LanguageFileDuplicateKeysTest extends TestCase
{
    public function testNoKeyIsAssignedTwiceInOneFile(): void
    {
        $root = dirname(__DIR__, 2);
        $files = [
            ...glob($root . '/backend/lang/*.php'),
            ...glob($root . '/Okay/Modules/*/*/Backend/lang/*.php'),
        ];
        $this->assertNotEmpty($files);

        $duplicates = [];
        foreach ($files as $file) {
            preg_match_all('~^\$lang\[([\'"])(.+?)\1\]\s*=~m', (string) file_get_contents($file), $matches);
            foreach (array_keys(array_filter(array_count_values($matches[2]), fn (int $n): bool => $n > 1)) as $key) {
                $duplicates[] = substr($file, strlen($root) + 1) . ': ' . $key;
            }
        }

        $this->assertSame([], $duplicates);
    }
}
