<?php

namespace Admin;

use Okay\Admin\Controllers\SettingsIndexingAdmin;
use Okay\Helpers\BrandFeatureLandingsHelper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Поріг і частка посадкових «бренд + властивість» зберігаються лише в межах, які розуміє
 * BrandFeatureLandingsHelper. Усе інше стає 0, тобто «типове значення»: так форма не покаже
 * число, якого хелпер насправді не застосовує.
 */
class SettingsIndexingBrandFeatureLandingsTest extends TestCase
{
    private const string TEMPLATE = __DIR__ . '/../../backend/design/html/settings_indexing.tpl';

    public static function settings(): array
    {
        return [
            'поріг' => ['brand_feature_landings_min_products', 100000],
            'частка' => ['brand_feature_landings_max_share', 100],
        ];
    }

    #[DataProvider('settings')]
    public function testFormHasTheField(string $setting, int $max): void
    {
        $this->assertMatchesRegularExpression(
            '~<input name="' . $setting . '"[^>]*value="[^"]*\{\$settings->' . $setting . '\|escape\}~',
            file_get_contents(self::TEMPLATE)
        );
    }

    #[DataProvider('settings')]
    public function testOnlyValuesTheHelperAppliesAreKept(string $setting, int $max): void
    {
        foreach ([-1, 0, 1, 5, 80, 100, 101, $max, $max + 1] as $value) {
            $this->assertSame(
                $value >= 1 && $value <= $max ? $value : 0,
                $this->sanitize($setting, $value),
                "$setting = $value"
            );
        }
    }

    public function testDefaultsAreTheDocumentedOnes(): void
    {
        $this->assertSame(5, BrandFeatureLandingsHelper::DEFAULT_MIN_PRODUCTS);
        $this->assertSame(80, BrandFeatureLandingsHelper::DEFAULT_MAX_SHARE);
    }

    private function sanitize(string $setting, int $value): int
    {
        return (new ReflectionClass(SettingsIndexingAdmin::class))
            ->getMethod('brandFeatureLandingsSetting')
            ->invoke(null, $setting, $value);
    }
}
