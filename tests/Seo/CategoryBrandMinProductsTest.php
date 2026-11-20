<?php

namespace Seo;

use Okay\Admin\Controllers\SettingsIndexingAdmin;
use Okay\Core\EntityFactory;
use Okay\Core\Response;
use Okay\Core\Settings;
use Okay\Entities\CategoriesEntity;
use Okay\Entities\ProductsEntity;
use Okay\Helpers\BrandFeatureLandingsHelper;
use Okay\Helpers\CanonicalHelper;
use Okay\Helpers\FilterHelper;
use Okay\Helpers\MainHelper;
use Okay\Helpers\MetaRobotsHelper;
use Okay\Helpers\SiteMapHelper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * robots_category_brand_min_products: бренд-фільтр категорії, де товарів менше за поріг,
 * отримує noindex,follow і не потрапляє в карту сайту. 0 — поведінка без порогу.
 */
class CategoryBrandMinProductsTest extends TestCase
{
    private const array BRAND = [7];

    private $argv;

    protected function setUp(): void
    {
        require_once 'Okay/Core/config/constants.php';
        $this->argv = $GLOBALS['argv'] ?? null;
        $GLOBALS['argv'] = ['ok', 'root_url=https://example.com'];
    }

    protected function tearDown(): void
    {
        $GLOBALS['argv'] = $this->argv;
    }

    public static function robotsCases(): array
    {
        require_once 'Okay/Core/config/constants.php';

        return [
            'поріг вимкнено, 1 товар' => [0, 1, ROBOTS_INDEX_FOLLOW],
            'поріг вимкнено, 0 товарів' => [0, 0, ROBOTS_INDEX_FOLLOW],
            'поріг 2, 1 товар' => [2, 1, ROBOTS_NOINDEX_FOLLOW],
            'поріг 2, 0 товарів' => [2, 0, ROBOTS_NOINDEX_FOLLOW],
            'поріг 2, 2 товари' => [2, 2, ROBOTS_INDEX_FOLLOW],
            'поріг 2, 3 товари' => [2, 3, ROBOTS_INDEX_FOLLOW],
            'поріг поза межами' => [MetaRobotsHelper::CATEGORY_BRAND_MIN_PRODUCTS_MAX + 1, 1, ROBOTS_INDEX_FOLLOW],
            'поріг рядком' => ['2', 1, ROBOTS_NOINDEX_FOLLOW],
        ];
    }

    #[DataProvider('robotsCases')]
    public function testBrandFilterRobots($minProducts, int $productsCount, int $expected): void
    {
        $this->assertSame(
            $expected,
            $this->robots($minProducts)->getCatalogRobots('', [], [], self::BRAND, $productsCount)
        );
    }

    /** Без кількості (інші сторінки, карта сайту, метадані) поріг не діє. */
    public function testWithoutCountNothingChanges(): void
    {
        $this->assertSame(ROBOTS_INDEX_FOLLOW, $this->robots(2)->getCatalogRobots('', [], [], self::BRAND));
    }

    /** Суворіше налаштування бренд-фільтра поріг не послаблює. */
    public function testStricterBrandSettingWins(): void
    {
        $this->assertSame(
            ROBOTS_NOINDEX_NOFOLLOW,
            $this->robots(2, ROBOTS_NOINDEX_NOFOLLOW)->getCatalogRobots('', [], [], self::BRAND, 1)
        );
    }

    /** Поріг стосується лише одного бренду без інших фільтрів. */
    public function testOtherFiltersAreUntouched(): void
    {
        $helper = $this->robots(2);
        $helper->setAvailableFeatures([
            (object)['id' => 42, 'features_values' => [(object)['value' => 'red', 'to_index' => 1]]],
        ]);

        $this->assertSame(ROBOTS_INDEX_FOLLOW, $helper->getCatalogRobots('', [], [], [], 1), 'категорія без фільтра');
        $this->assertSame(ROBOTS_INDEX_FOLLOW, $helper->getCatalogRobots('', [], [], [7, 8], 1), 'два бренди');
        $this->assertSame(ROBOTS_INDEX_FOLLOW, $helper->getCatalogRobots('', ['discounted'], [], self::BRAND, 1), 'filter-*');
        $this->assertSame(ROBOTS_INDEX_FOLLOW, $helper->getCatalogRobots('', [], [42 => ['red']], self::BRAND, 1), 'властивість');
    }

    public function testControllerPassesListingCount(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/Okay/Controllers/CategoryController.php');

        $this->assertMatchesRegularExpression(
            '~getCatalogRobots\([^;]*\$productsFilter\[\'brand_id\'\] \?\? \[\],\s*\(int\)\$this->design->getVar\(\'total_products_num\'\)\)~',
            $source
        );
    }

    public function testSitemapSkipsBrandsBelowThreshold(): void
    {
        $this->assertSame([2], array_keys($this->sitemapBrands(2, [1 => 1, 2 => 2])));
    }

    /** Бренд, якого підрахунок не повернув (усі товари приховані чи не в наявності), — 0 товарів. */
    public function testSitemapTreatsMissingCountAsZero(): void
    {
        $this->assertSame([2], array_keys($this->sitemapBrands(1, [2 => 1])));
    }

    /** Без порогу підрахунку немає зовсім: та сама кількість запитів, що й до налаштування. */
    public function testSitemapWithoutThresholdDoesNotCount(): void
    {
        $this->assertSame([1, 2], array_keys($this->sitemapBrands(0, null)));
    }

    /** Процедура карти бере відфільтрований набір: коли всі бренди нижче порогу, адрес немає. */
    public function testSitemapProcedureWritesNoBrandBelowThreshold(): void
    {
        $helper = $this->sitemapHelper(2, [1 => 1, 2 => 1]);
        $helper->writeCategoryBrandsProcedure();

        $this->assertSame([], $helper->written);
    }

    public function testAdminKeepsOnlyValuesTheHelperApplies(): void
    {
        $max = MetaRobotsHelper::CATEGORY_BRAND_MIN_PRODUCTS_MAX;
        $sanitize = (new ReflectionClass(SettingsIndexingAdmin::class))->getMethod('categoryBrandMinProducts');

        foreach ([-1, 0, 1, 2, $max, $max + 1] as $value) {
            $this->assertSame($value >= 1 && $value <= $max ? $value : 0, $sanitize->invoke(null, $value), (string)$value);
        }
    }

    public function testFormHasTheField(): void
    {
        $this->assertMatchesRegularExpression(
            '~<input name="robots_category_brand_min_products"[^>]*value="[^"]*\{\$settings->robots_category_brand_min_products\|escape\}~',
            file_get_contents(dirname(__DIR__, 2) . '/backend/design/html/settings_indexing.tpl')
        );
    }

    private function robots($minProducts, int $categoryBrand = ROBOTS_INDEX_FOLLOW): MetaRobotsHelper
    {
        $settings = $this->createStub(Settings::class);
        $settings->method('get')->willReturnCallback(
            static fn($param) => match ($param) {
                'robots_category_brand_min_products' => $minProducts,
                'catalog_page_all_max_items' => PAGE_ALL_MAX_ITEMS,
                default => null,
            }
        );

        $helper = new MetaRobotsHelper($settings);
        $helper->setParams(
            ROBOTS_INDEX_FOLLOW,
            ROBOTS_INDEX_FOLLOW,
            $categoryBrand,
            ROBOTS_INDEX_FOLLOW,
            ROBOTS_INDEX_FOLLOW,
            ROBOTS_NOINDEX_FOLLOW,
            2,
            1,
            1,
            1,
            3
        );

        return $helper;
    }

    /**
     * @param array<int, int>|null $counts товарів за id бренду; null — підрахунок не має відбутися
     * @return array<int, object>
     */
    private function sitemapBrands(int $minProducts, ?array $counts): array
    {
        return $this->sitemapHelper($minProducts, $counts)
            ->filterCategoryBrandsByMinProducts($this->category(), $this->brands());
    }

    private function category(): object
    {
        $category = (object)['id' => 3, 'url' => 'bikes', 'visible' => 1, 'children' => [3, 4]];
        $category->path = [$category];

        return $category;
    }

    private function brands(): array
    {
        return [
            1 => (object)['id' => 1, 'url' => 'small'],
            2 => (object)['id' => 2, 'url' => 'big'],
        ];
    }

    /**
     * @param array<int, int>|null $counts
     */
    private function sitemapHelper(int $minProducts, ?array $counts): SiteMapHelper
    {
        $categoriesEntity = $this->createStub(CategoriesEntity::class);
        $categoriesEntity->method('find')->willReturn([$this->category()]);

        $productsEntity = $this->createMock(ProductsEntity::class);
        if ($counts === null) {
            $productsEntity->expects($this->never())->method('countGroupedByBrand');
        } else {
            $productsEntity->expects($this->once())->method('countGroupedByBrand')
                ->with(['category_id' => [3, 4], 'visible' => 1])
                ->willReturn(array_map(
                    static fn(int $id, int $count) => (object)['brand_id' => $id, 'count' => $count],
                    array_keys($counts),
                    $counts
                ));
        }

        $entityFactory = $this->createStub(EntityFactory::class);
        $entityFactory->method('get')->willReturnCallback(
            static fn(string $class) => $class === ProductsEntity::class ? $productsEntity : $categoriesEntity
        );

        $filterHelper = $this->createStub(FilterHelper::class);
        $filterHelper->method('prepareFilterGetBrands')->willReturn([]);
        $filterHelper->method('getBrands')->willReturn($this->brands());

        $canonicalHelper = $this->createStub(CanonicalHelper::class);
        $canonicalHelper->method('getCatalogCanonicalData')->willReturn(false);

        $landingsHelper = $this->createStub(BrandFeatureLandingsHelper::class);
        $landingsHelper->method('getCountFilter')->willReturnCallback(
            static fn(array $ids) => ['category_id' => $ids, 'visible' => 1]
        );

        $mainHelper = $this->createStub(MainHelper::class);
        $mainHelper->method('getCurrentLanguage')->willReturn((object)['label' => 'ua']);

        return new class(
            $entityFactory,
            $this->createStub(Response::class),
            $mainHelper,
            $this->createStub(Settings::class),
            $filterHelper,
            $this->robots($minProducts),
            $canonicalHelper,
            $landingsHelper
        ) extends SiteMapHelper {
            public array $written = [];

            public function write(array $params, $countUrl = false)
            {
                $this->written[] = $params['url'];
            }
        };
    }
}
