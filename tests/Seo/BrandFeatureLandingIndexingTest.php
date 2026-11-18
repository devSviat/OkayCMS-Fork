<?php

namespace Seo;

use Okay\Core\Settings;
use Okay\Helpers\CanonicalHelper;
use Okay\Helpers\MetaRobotsHelper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Посадкова «бренд + значення властивості» індексується сама по собі, а її пагінація
 * підпорядковується тим самим налаштуванням, що й пагінація будь-якого фільтра.
 * Глибина фільтра, to_index значень і правила бренду/властивостей її не стосуються.
 */
class BrandFeatureLandingIndexingTest extends TestCase
{
    private const array FEATURES = ['type' => ['brush']];
    private const array BRANDS = [7];

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

    /**
     * Найсуворіші налаштування фільтрів: якби посадкова йшла старою гілкою,
     * вона б отримала noindex і canonical на категорію.
     */
    private function robots(int $filterPagination, $pageAllMaxItems = PAGE_ALL_MAX_ITEMS): MetaRobotsHelper
    {
        $helper = new MetaRobotsHelper($this->settings($pageAllMaxItems));
        $helper->setParams(
            ROBOTS_INDEX_FOLLOW,
            ROBOTS_NOINDEX_FOLLOW,
            ROBOTS_NOINDEX_NOFOLLOW,
            ROBOTS_NOINDEX_NOFOLLOW,
            ROBOTS_NOINDEX_NOFOLLOW,
            $filterPagination,
            0,
            0,
            0,
            0,
            0
        );

        return $helper;
    }

    private function canonical(int $filterPagination, $pageAllMaxItems = PAGE_ALL_MAX_ITEMS): CanonicalHelper
    {
        $helper = new CanonicalHelper($this->settings($pageAllMaxItems));
        $helper->setParams(
            CANONICAL_CURRENT_PAGE,
            CANONICAL_CURRENT_PAGE,
            CANONICAL_ABSENT,
            CANONICAL_ABSENT,
            CANONICAL_ABSENT,
            $filterPagination,
            0,
            0,
            0,
            0,
            0
        );

        return $helper;
    }

    public static function robotsCases(): array
    {
        require_once 'Okay/Core/config/constants.php';

        $cases = [];
        foreach ([ROBOTS_INDEX_FOLLOW, ROBOTS_NOINDEX_FOLLOW, ROBOTS_NOINDEX_NOFOLLOW] as $filterPagination) {
            $cases["перша сторінка, пагінація фільтра $filterPagination"] = ['', $filterPagination, ROBOTS_INDEX_FOLLOW];
            $cases["page-2, пагінація фільтра $filterPagination"] = ['2', $filterPagination, $filterPagination];
            $cases["page-all, пагінація фільтра $filterPagination"] = ['all', $filterPagination, $filterPagination];
        }

        return $cases;
    }

    #[DataProvider('robotsCases')]
    public function testRobots(string $page, int $filterPagination, int $expected): void
    {
        $this->assertSame($expected, $this->robots($filterPagination)->getBrandFeatureLandingRobots($page));
    }

    /** Вимкнений page-all віддає першу сторінку, тож і robots — як у першої. */
    public function testDisabledPageAllIsTheFirstPage(): void
    {
        $this->assertSame(
            ROBOTS_INDEX_FOLLOW,
            $this->robots(ROBOTS_NOINDEX_NOFOLLOW, PAGE_ALL_OFF)->getBrandFeatureLandingRobots('all')
        );
    }

    /** Старі правила категорії лишаються: для тієї ж адреси звичайна гілка дає noindex. */
    public function testCatalogRobotsAreUntouched(): void
    {
        $helper = $this->robots(ROBOTS_NOINDEX_FOLLOW);
        $helper->setAvailableFeatures([
            (object)['id' => 5, 'features_values' => [(object)['value' => 'brush', 'to_index' => 0]]],
        ]);

        $this->assertSame(ROBOTS_NOINDEX_NOFOLLOW, $helper->getCatalogRobots('', [], [5 => ['brush']], self::BRANDS));
        $this->assertSame(ROBOTS_INDEX_FOLLOW, $helper->getBrandFeatureLandingRobots(''));
    }

    public static function canonicalCases(): array
    {
        require_once 'Okay/Core/config/constants.php';

        $withoutFilter = ['page' => null, 'type' => null, 'brand' => null, 'sort' => null];

        return [
            'перша сторінка' => ['', CANONICAL_CURRENT_PAGE, ['sort' => null]],
            'перша сторінка, пагінація без фільтра' => ['', CANONICAL_WITHOUT_FILTER_FIRST_PAGE, ['sort' => null]],
            'перша сторінка, пагінація без canonical' => ['', CANONICAL_ABSENT, ['sort' => null]],
            'page-2 на себе' => ['2', CANONICAL_CURRENT_PAGE, ['page' => '2', 'sort' => null]],
            'page-2 на першу' => ['2', CANONICAL_FIRST_PAGE, ['page' => null, 'sort' => null]],
            'page-2 без фільтра' => ['2', CANONICAL_WITHOUT_FILTER_FIRST_PAGE, $withoutFilter],
            'page-2 без canonical' => ['2', CANONICAL_ABSENT, false],
            'page-all на себе' => ['all', CANONICAL_CURRENT_PAGE, ['page' => 'all', 'sort' => null]],
        ];
    }

    #[DataProvider('canonicalCases')]
    public function testCanonical(string $page, int $filterPagination, $expected): void
    {
        $this->assertEquals(
            $expected,
            $this->canonical($filterPagination)->getBrandFeatureLandingCanonicalData($page, self::FEATURES, self::BRANDS)
        );
    }

    /** Без явного 'page' => null будівник адреси лишив би сегмент page-all. */
    public function testDisabledPageAllPointsToTheFirstPage(): void
    {
        $this->assertEquals(
            ['page' => null, 'sort' => null],
            $this->canonical(CANONICAL_CURRENT_PAGE, PAGE_ALL_OFF)->getBrandFeatureLandingCanonicalData('all', self::FEATURES, self::BRANDS)
        );
    }

    public function testCatalogCanonicalIsUntouched(): void
    {
        $helper = $this->canonical(CANONICAL_CURRENT_PAGE);

        $this->assertSame(
            ['type' => null, 'brand' => null, 'sort' => null],
            $helper->getCatalogCanonicalData('', [], self::FEATURES, self::BRANDS)
        );
    }
}
