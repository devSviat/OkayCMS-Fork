<?php

namespace Admin;

use PHPUnit\Framework\TestCase;

/**
 * Модуль із ua.php мусить мати en.php і ru.php з тими самими ключами:
 * бракує ключа — адмінка цією мовою показує сирий ідентифікатор або
 * порожній підпис. Так у Feeds підпис «Компанія» роками був порожнім
 * українською й англійською через опечатку в назві ключа.
 */
class ModuleLanguageParityTest extends TestCase
{
    public function testEveryModuleHasTheSameKeysInEachLanguage(): void
    {
        $root = dirname(__DIR__, 2);
        $directories = glob($root . '/Okay/Modules/*/*/Backend/lang', GLOB_ONLYDIR) ?: [];
        $this->assertNotEmpty($directories);

        $problems = [];
        foreach ($directories as $directory) {
            $module = substr($directory, strlen($root . '/Okay/Modules/'), -strlen('/Backend/lang'));
            if (!is_file("{$directory}/ua.php")) {
                continue;
            }
            $reference = array_keys($this->load("{$directory}/ua.php"));

            foreach (['en', 'ru'] as $language) {
                if (!is_file("{$directory}/{$language}.php")) {
                    $problems[] = "{$module}: немає {$language}.php";
                    continue;
                }
                $keys = array_keys($this->load("{$directory}/{$language}.php"));
                foreach (array_diff($reference, $keys) as $key) {
                    $problems[] = "{$module}: у {$language}.php бракує {$key}";
                }
                foreach (array_diff($keys, $reference) as $key) {
                    $problems[] = "{$module}: у {$language}.php зайвий {$key}";
                }
            }
        }

        $this->assertSame([], $problems);
    }

    /** @return array<string, string> */
    private function load(string $file): array
    {
        $lang = [];
        include $file;

        return $lang;
    }
}
