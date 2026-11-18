<?php

namespace Helpers;

use Okay\Core\EntityFactory;
use Okay\Core\Settings;
use Okay\Helpers\FilterHelper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Сторінка бренду посилається на /catalog/x/brand-y кожної своєї категорії. CategoryController
 * віддає 404, коли бренду немає в getBrands() для піддерева категорії (зокрема коли бренд там
 * єдиний, а hide_single_filters увімкнено). Позначка «без фільтра» мусить збігатися з цією
 * перевіркою для кожної пари і рахуватись одним запитом на весь список.
 */
class FilterHelperCategoriesWithoutBrandFilterTest extends TestCase
{
    /**
     * Дерево: 1 → 2, 3; 4 окремо; 5 → 6.
     * Пари «категорія → бренди» з видимими товарами.
     */
    private const array CHILDREN = [
        1 => [1, 2, 3],
        2 => [2],
        3 => [3],
        4 => [4],
        5 => [5, 6],
        6 => [6],
    ];

    private const array PAIRS = [
        1 => [10],
        2 => [10, 11],
        3 => [10],
        4 => [12],
        6 => [13],
    ];

    public static function hideSingleFilters(): array
    {
        return ['hide_single_filters увімкнено' => [1], 'вимкнено' => [0]];
    }

    #[DataProvider('hideSingleFilters')]
    public function testMarksExactlyTheCategoriesWhereTheBrandFilterIs404($hideSingleFilters): void
    {
        $entity = $this->entity();
        $helper = $this->makeHelper($entity, $hideSingleFilters);
        $categories = $this->categories();

        foreach ([10, 11, 12, 13, 99] as $brandId) {
            $marked = $helper->getCategoriesWithoutBrandFilter($brandId, $categories);

            foreach ($categories as $category) {
                $brands = $helper->getBrands($helper->getCategoryBrandsFilter($category->children));
                $this->assertSame(
                    !isset($brands[$brandId]),
                    in_array($category->id, $marked, true),
                    "категорія {$category->id}, бренд {$brandId}"
                );
            }
        }
    }

    public function testSingleBrandCategoriesAreMarkedWhenHidden(): void
    {
        $helper = $this->makeHelper($this->entity(), 1);

        $this->assertSame([3], $helper->getCategoriesWithoutBrandFilter(10, [$this->category(1), $this->category(2), $this->category(3)]));
        $this->assertSame([4], $helper->getCategoriesWithoutBrandFilter(12, [$this->category(4)]));
        $this->assertSame([5, 6], $helper->getCategoriesWithoutBrandFilter(13, [$this->category(5), $this->category(6)]));
    }

    public function testNothingIsMarkedWhenSingleFiltersAreShown(): void
    {
        $helper = $this->makeHelper($this->entity(), 0);

        $this->assertSame([], $helper->getCategoriesWithoutBrandFilter(10, [$this->category(1), $this->category(2), $this->category(3)]));
    }

    public function testOneQueryForTheWholeList(): void
    {
        $entity = $this->entity();
        $helper = $this->makeHelper($entity, 1);

        $helper->getCategoriesWithoutBrandFilter(10, $this->categories());

        $this->assertSame(1, $entity->pairsCalls);
        $this->assertSame(0, $entity->findCalls);
        $this->assertSame(
            $helper->getCategoryBrandsFilter([1, 2, 3, 4, 5, 6]),
            $entity->lastPairsFilter,
            'пари мають рахуватись тим самим фільтром, що й getBrands() у CategoryController'
        );
    }

    public function testNoQueryWithoutCategories(): void
    {
        $entity = $this->entity();

        $this->assertSame([], $this->makeHelper($entity, 1)->getCategoriesWithoutBrandFilter(10, []));
        $this->assertSame(0, $entity->pairsCalls);
    }

    private function category(int $id): object
    {
        return (object)['id' => $id, 'children' => self::CHILDREN[$id]];
    }

    private function categories(): array
    {
        return array_map(fn(int $id) => $this->category($id), array_keys(self::CHILDREN));
    }

    private function entity(): object
    {
        return new class(self::PAIRS) {
            public int $findCalls = 0;
            public int $pairsCalls = 0;
            public ?array $lastPairsFilter = null;

            public function __construct(private array $pairs)
            {
            }

            public function __call(string $name, array $arguments): static
            {
                return $this;
            }

            public function find(array $filter = []): array
            {
                $this->findCalls++;
                $brands = [];
                foreach ($this->findCategoriesBrandsIds($filter, false) as $brandsIds) {
                    foreach ($brandsIds as $brandId) {
                        $brands[$brandId] = (object)['id' => $brandId];
                    }
                }
                ksort($brands);

                return $brands;
            }

            public function findCategoriesBrandsIds(array $filter, bool $count = true): array
            {
                if ($count) {
                    $this->pairsCalls++;
                    $this->lastPairsFilter = $filter;
                }

                return array_intersect_key($this->pairs, array_flip((array)$filter['category_id']));
            }
        };
    }

    private function makeHelper(object $entity, $hideSingleFilters): FilterHelper
    {
        $factory = $this->createStub(EntityFactory::class);
        $factory->method('get')->willReturn($entity);

        $settings = $this->createStub(Settings::class);
        $settings->method('get')->willReturnCallback(
            static fn($param) => $param === 'hide_single_filters' ? $hideSingleFilters : null
        );

        $reflection = new ReflectionClass(FilterHelper::class);
        $helper = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('entityFactory')->setValue($helper, $factory);
        $reflection->getProperty('settings')->setValue($helper, $settings);

        return $helper;
    }
}
