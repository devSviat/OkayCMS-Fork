<?php

namespace Seo;

use Okay\Core\OkayContainer\Reference\ParameterReference;
use Okay\Core\Settings;
use Okay\Helpers\CanonicalHelper;
use Okay\Helpers\MetaRobotsHelper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * /all-products/brand-x показує ті самі товари, що й /brand/x. Окремі налаштування
 * canonical і robots для цього фільтра дозволяють склеїти дубль зі сторінкою бренду,
 * не чіпаючи «категорія × бренд». Незадане налаштування мусить давати рівно стару
 * поведінку: на інсталяціях без нього нічого не змінюється.
 */
class AllProductsBrandIndexingTest extends TestCase
{
    private const int BRAND = 7;

    protected function setUp(): void
    {
        require_once 'Okay/Core/config/constants.php';
    }

    private function settings($pageAllMaxItems = PAGE_ALL_MAX_ITEMS): Settings
    {
        $settings = $this->createStub(Settings::class);
        $settings->method('get')->willReturnCallback(
            static fn($param) => $param === 'catalog_page_all_max_items' ? $pageAllMaxItems : null
        );

        return $settings;
    }

    private function canonical(
        int $categoryBrand,
        $allProductsBrand,
        int $filterPagination = CANONICAL_CURRENT_PAGE,
        int $maxFilterDepth = 2,
        $pageAllMaxItems = PAGE_ALL_MAX_ITEMS
    ): CanonicalHelper {
        $helper = new CanonicalHelper($this->settings($pageAllMaxItems));
        $helper->setParams(
            CANONICAL_CURRENT_PAGE,
            CANONICAL_CURRENT_PAGE,
            $categoryBrand,
            CANONICAL_WITH_FILTER,
            CANONICAL_WITH_FILTER,
            $filterPagination,
            2,
            2,
            2,
            2,
            $maxFilterDepth,
            $allProductsBrand
        );

        return $helper;
    }

    private function robots(int $categoryBrand, $allProductsBrand): MetaRobotsHelper
    {
        $helper = new MetaRobotsHelper($this->settings());
        $helper->setParams(
            ROBOTS_INDEX_FOLLOW,
            ROBOTS_INDEX_FOLLOW,
            $categoryBrand,
            ROBOTS_NOINDEX_FOLLOW,
            ROBOTS_INDEX_FOLLOW,
            ROBOTS_NOINDEX_FOLLOW,
            2,
            2,
            2,
            2,
            2,
            $allProductsBrand
        );
        $helper->setAvailableFeatures([
            (object)['id' => 3, 'features_values' => [(object)['value' => 'red', 'to_index' => 1]]],
        ]);

        return $helper;
    }

    /**
     * Так DI передає налаштування, якого ще немає в базі (null) або яке
     * збережене як «як для категорії».
     */
    public static function unsetValues(): array
    {
        return [
            'не передане' => [null],
            'порожній рядок' => [''],
            'нуль' => ['0'],
        ];
    }

    public static function pages(): array
    {
        return ['', '2', 'all'];
    }

    public static function filters(): array
    {
        return [
            'без фільтра' => [[], [], []],
            'бренд' => [[], [], [self::BRAND]],
            'два бренди' => [[], [], [self::BRAND, 8]],
            'три бренди' => [[], [], [self::BRAND, 8, 9]],
            'бренд і other' => [['discounted'], [], [self::BRAND]],
            'бренд і властивість' => [[], ['color' => ['red']], [self::BRAND]],
            'властивість' => [[], ['color' => ['red']], []],
        ];
    }

    public static function defaultParityCases(): iterable
    {
        require_once 'Okay/Core/config/constants.php';

        foreach (self::unsetValues() as $unsetName => [$unset]) {
            foreach ([CANONICAL_WITHOUT_FILTER, CANONICAL_WITH_FILTER, CANONICAL_ABSENT] as $categoryBrand) {
                foreach ([CANONICAL_WITHOUT_FILTER_FIRST_PAGE, CANONICAL_FIRST_PAGE, CANONICAL_CURRENT_PAGE, CANONICAL_ABSENT] as $filterPagination) {
                    foreach (self::pages() as $page) {
                        foreach (self::filters() as $filterName => [$other, $features, $brands]) {
                            yield "$unsetName/$categoryBrand/$filterPagination/'$page'/$filterName" => [
                                $unset, $categoryBrand, $filterPagination, $page, $other, $features, $brands,
                            ];
                        }
                    }
                }
            }
        }
    }

    #[DataProvider('defaultParityCases')]
    public function testUnsetCanonicalIsTheCategoryBrandRule(
        $unset,
        int $categoryBrand,
        int $filterPagination,
        string $page,
        array $other,
        array $features,
        array $brands
    ): void {
        $helper = $this->canonical($categoryBrand, $unset, $filterPagination);

        $this->assertSame(
            $helper->getCatalogCanonicalData($page, $other, $features, $brands),
            $helper->getAllProductsCanonicalData($page, $other, $features, $brands)
        );
        $this->assertNull(
            $helper->getAllProductsBrandPageCanonicalData($page, $other, $features, $brands),
            'без налаштування canonical не має вести на сторінку бренду'
        );
    }

    #[DataProvider('unsetValues')]
    public function testUnsetRobotsIsTheCategoryBrandRule($unset): void
    {
        foreach ([ROBOTS_INDEX_FOLLOW, ROBOTS_NOINDEX_FOLLOW, ROBOTS_NOINDEX_NOFOLLOW] as $categoryBrand) {
            $helper = $this->robots($categoryBrand, $unset);
            foreach (self::pages() as $page) {
                foreach (self::filters() as $name => [$other, , $brands]) {
                    $features = $name === 'бренд і властивість' || $name === 'властивість' ? [3 => ['red']] : [];
                    $this->assertSame(
                        $helper->getCatalogRobots($page, $other, $features, $brands),
                        $helper->getAllProductsRobots($page, $other, $features, $brands),
                        "$categoryBrand/'$page'/$name"
                    );
                }
            }
        }
    }

    public function testOwnRobotsApplyOnlyToAllProducts(): void
    {
        $helper = $this->robots(ROBOTS_INDEX_FOLLOW, (string)ROBOTS_NOINDEX_FOLLOW);

        $this->assertSame(ROBOTS_NOINDEX_FOLLOW, $helper->getAllProductsRobots('', [], [], [self::BRAND]));
        $this->assertSame(ROBOTS_INDEX_FOLLOW, $helper->getCatalogRobots('', [], [], [self::BRAND]));
        $this->assertSame(ROBOTS_INDEX_FOLLOW, $helper->getAllProductsRobots('', [], [], []), 'без бренду правило бренду не діє');
    }

    public function testOwnCanonicalAppliesOnlyToAllProducts(): void
    {
        $helper = $this->canonical(CANONICAL_WITH_FILTER, (string)CANONICAL_WITHOUT_FILTER);

        $this->assertSame(['brand' => null, 'sort' => null], $helper->getAllProductsCanonicalData('', [], [], [self::BRAND]));
        $this->assertSame(['sort' => null], $helper->getCatalogCanonicalData('', [], [], [self::BRAND]));
    }

    public static function brandPageCases(): array
    {
        require_once 'Okay/Core/config/constants.php';

        return [
            'перша сторінка' => ['', CANONICAL_CURRENT_PAGE, ['brand' => null, 'sort' => null]],
            'page-2, пагінація фільтра на себе' => ['2', CANONICAL_CURRENT_PAGE, ['brand' => null, 'page' => '2', 'sort' => null]],
            'page-2, пагінація фільтра на першу' => ['2', CANONICAL_FIRST_PAGE, ['brand' => null, 'page' => null, 'sort' => null]],
            'page-all, пагінація фільтра на себе' => ['all', CANONICAL_CURRENT_PAGE, ['brand' => null, 'page' => 'all', 'sort' => null]],
        ];
    }

    #[DataProvider('brandPageCases')]
    public function testBrandPageCanonicalKeepsThePaginationRule(string $page, int $filterPagination, array $expected): void
    {
        $helper = $this->canonical(CANONICAL_WITHOUT_FILTER, (string)CANONICAL_BRAND_PAGE, $filterPagination);

        $this->assertEquals($expected, $helper->getAllProductsBrandPageCanonicalData($page, [], [], [self::BRAND]));
    }

    /** Вимкнений page-all віддає першу сторінку, тож і canonical — на першу сторінку бренду. */
    public function testDisabledPageAllPointsToTheBrandFirstPage(): void
    {
        $helper = $this->canonical(CANONICAL_WITHOUT_FILTER, (string)CANONICAL_BRAND_PAGE, CANONICAL_CURRENT_PAGE, 2, PAGE_ALL_OFF);

        $this->assertEquals(
            ['brand' => null, 'page' => null, 'sort' => null],
            $helper->getAllProductsBrandPageCanonicalData('all', [], [], [self::BRAND])
        );
    }

    public static function notABrandPageCases(): array
    {
        require_once 'Okay/Core/config/constants.php';

        return [
            'два бренди' => ['', [], [], [self::BRAND, 8], CANONICAL_CURRENT_PAGE, 2],
            'бренд і other' => ['', ['discounted'], [], [self::BRAND], CANONICAL_CURRENT_PAGE, 2],
            'бренд і властивість' => ['', [], ['color' => ['red']], [self::BRAND], CANONICAL_CURRENT_PAGE, 2],
            'без бренду' => ['', [], [], [], CANONICAL_CURRENT_PAGE, 2],
            'пагінація фільтра без фільтра' => ['2', [], [], [self::BRAND], CANONICAL_WITHOUT_FILTER_FIRST_PAGE, 2],
            'пагінація фільтра без canonical' => ['2', [], [], [self::BRAND], CANONICAL_ABSENT, 2],
            'глибина фільтра 0' => ['', [], [], [self::BRAND], CANONICAL_CURRENT_PAGE, 0],
        ];
    }

    /**
     * Де адреса не зводиться до сторінки бренду, діє правило «категорія × бренд»,
     * тобто те, що було до налаштування.
     */
    #[DataProvider('notABrandPageCases')]
    public function testOtherUrlsKeepTheCategoryBrandRule(
        string $page,
        array $other,
        array $features,
        array $brands,
        int $filterPagination,
        int $maxFilterDepth
    ): void {
        $brandPage = $this->canonical(CANONICAL_WITHOUT_FILTER, (string)CANONICAL_BRAND_PAGE, $filterPagination, $maxFilterDepth);
        $before = $this->canonical(CANONICAL_WITHOUT_FILTER, null, $filterPagination, $maxFilterDepth);

        $this->assertNull($brandPage->getAllProductsBrandPageCanonicalData($page, $other, $features, $brands));
        $this->assertSame(
            $before->getAllProductsCanonicalData($page, $other, $features, $brands),
            $brandPage->getAllProductsCanonicalData($page, $other, $features, $brands)
        );
    }

    /** Без проводки в DI налаштування мовчки не діяло б. */
    public function testSettingsReachTheHelpers(): void
    {
        $parameters = include 'Okay/Core/config/parameters.php';
        $definitions = include 'Okay/Core/config/helpers.php';

        $expected = [
            CanonicalHelper::class => ['seo.canonical.all_products_brand', '{%canonical_all_products_brand%}'],
            MetaRobotsHelper::class => ['seo.robots.all_products_brand', '{%robots_all_products_brand%}'],
        ];

        foreach ($expected as $class => [$reference, $setting]) {
            $names = array_map(
                static fn(ParameterReference $argument) => $argument->getName(),
                $definitions[$class]['calls'][0]['arguments']
            );
            $this->assertSame($reference, end($names), $class);

            [, $group, $key] = explode('.', $reference);
            $this->assertSame($setting, $parameters['seo'][$group][$key]);
        }
    }
}
