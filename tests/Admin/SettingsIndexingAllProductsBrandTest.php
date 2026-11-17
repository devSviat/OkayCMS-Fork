<?php

namespace Admin;

use Okay\Admin\Controllers\SettingsIndexingAdmin;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Налаштування індексації «всіх товарів із брендом» зберігають лише те, що пропонує форма:
 * будь-яке інше число хелпери прочитали б як правило (наприклад, CANONICAL_PAGE_ALL),
 * а форма не показала б, що вибрано. Невідоме значення стає «як для категорії» (0).
 */
class SettingsIndexingAllProductsBrandTest extends TestCase
{
    private const string TEMPLATE = __DIR__ . '/../../backend/design/html/settings_indexing.tpl';

    protected function setUp(): void
    {
        require_once 'Okay/Core/config/constants.php';
    }

    public static function settings(): array
    {
        return [
            'canonical' => ['canonical_all_products_brand', 'CANONICAL_ALL_PRODUCTS_BRAND'],
            'robots' => ['robots_all_products_brand', 'ROBOTS_ALL_PRODUCTS_BRAND'],
        ];
    }

    #[DataProvider('settings')]
    public function testAllowedValuesAreExactlyTheFormOptions(string $setting, string $constant): void
    {
        $this->assertTrue(
            (bool)preg_match('~<select name="' . $setting . '"[^>]*>(.*?)</select>~s', file_get_contents(self::TEMPLATE), $select),
            "у формі немає $setting"
        );
        preg_match_all('~<option value="\{?([A-Z_0-9]+)\}?"~', $select[1], $options);
        $offered = array_map(static fn(string $v) => ctype_digit($v) ? (int)$v : constant($v), $options[1]);

        $allowed = $this->allowed($constant);
        sort($offered);
        sort($allowed);
        $this->assertSame($offered, $allowed);
    }

    #[DataProvider('settings')]
    public function testOnlyAllowedValuesAreKept(string $setting, string $constant): void
    {
        $allowed = $this->allowed($constant);

        foreach (range(-1, 10) as $value) {
            $this->assertSame(
                in_array($value, $allowed, true) ? $value : 0,
                $this->allowedOrInherit($value, $allowed),
                "$setting = $value"
            );
        }
    }

    private function allowed(string $constant): array
    {
        return (new ReflectionClass(SettingsIndexingAdmin::class))->getConstant($constant);
    }

    private function allowedOrInherit(int $value, array $allowed): int
    {
        return (new ReflectionClass(SettingsIndexingAdmin::class))
            ->getMethod('allowedOrInherit')
            ->invoke(null, $value, $allowed);
    }
}
