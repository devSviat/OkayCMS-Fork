<?php

namespace Admin;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Ключ, якого немає в одній мові адмінки, тихо показується англійською
 * (BackendTranslations бере запасний en.php), тож розбіжність ніхто не помічає.
 * Так у ru.php непомітно бракувало всього блоку менеджерів і сесій.
 */
class BackendLanguageParityTest extends TestCase
{
    private const string REFERENCE = 'ua';

    #[DataProvider('languageProvider')]
    public function testLanguageHasExactlyTheReferenceKeys(string $language): void
    {
        $reference = array_keys($this->load(self::REFERENCE));
        $keys = array_keys($this->load($language));

        $this->assertSame([], array_values(array_diff($reference, $keys)), "у {$language}.php бракує ключів");
        $this->assertSame([], array_values(array_diff($keys, $reference)), "у {$language}.php є зайві ключі");
    }

    public static function languageProvider(): array
    {
        return ['en' => ['en'], 'ru' => ['ru']];
    }

    /** @return array<string, string> */
    private function load(string $language): array
    {
        $lang = [];
        include dirname(__DIR__, 2) . "/backend/lang/{$language}.php";

        return $lang;
    }
}
