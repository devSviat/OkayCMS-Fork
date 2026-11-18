<?php

namespace Helpers;

use Okay\Core\EntityFactory;
use Okay\Core\Settings;
use Okay\Entities\BrandsEntity;
use Okay\Entities\CategoriesEntity;
use Okay\Entities\FeaturesEntity;
use Okay\Entities\FeaturesValuesEntity;
use Okay\Entities\ProductsEntity;
use Okay\Helpers\BrandFeatureLandingsHelper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Посадкова «бренд + значення властивості» відкривається для індексу лише за точної форми адреси
 * і лише коли сторінка не тонка (поріг) і не дублює бренд-фільтр (частка). Посилання, карта
 * сайту й robots беруть один і той самий набір, тож посилань на noindex не буває.
 *
 * Каталог: 1 → 2, 3 (піддерево); 4 окремо, в ній один бренд. Бренди 10 (acme), 11 (bolt).
 * Властивість 5 (type) позначена, 6 (color) — ні. Значення 51 brush, 52 motor, 53 filter, 61 red.
 *
 *   2: acme brush ×5, acme motor ×3, bolt brush ×2, acme без значення ×2
 *   3: acme filter ×6, acme без значення ×4, bolt filter ×5, bolt без значення ×5
 *   2 і 3 одночасно: acme brush ×1
 *   4: acme brush ×7, acme без значення ×3
 *
 * Пари з типовими налаштуваннями (поріг 5, частка 80 %, hide_single_filters):
 *   2: acme brush 6/11;  3: acme filter 6/11, bolt filter 5/10;
 *   1: acme brush 6/21 (товар у двох листах — один), acme filter 6/21, bolt filter 5/12;
 *   4: acme brush 7/10, але бренд у категорії єдиний.
 */
class BrandFeatureLandingsHelperTest extends TestCase
{
    private const array TREE = [1 => [1, 2, 3], 2 => [2], 3 => [3], 4 => [4]];
    private const array PARENT = [2 => 1, 3 => 1];

    public static function settingsCases(): array
    {
        return [
            'немає рядка' => [null, null, 5, 80],
            'порожньо' => ['', '', 5, 80],
            'нуль' => ['0', '0', 5, 80],
            'сміття' => ['abc', '-3', 5, 80],
            'частка понад 100' => ['3', '150', 3, 80],
            'задано' => ['7', '60', 7, 60],
        ];
    }

    #[DataProvider('settingsCases')]
    public function testSettingsFallBackToDefaults($min, $share, int $expectedMin, int $expectedShare): void
    {
        $helper = $this->helper(['brand_feature_landings_min_products' => $min, 'brand_feature_landings_max_share' => $share]);

        $this->assertSame($expectedMin, $helper->getMinProducts());
        $this->assertSame($expectedShare, $helper->getMaxShare());
    }

    public function testQualifyingLandingsOfASubtree(): void
    {
        $this->assertSame(
            ['10/51' => 6, '10/53' => 6, '11/53' => 5],
            $this->landings($this->helper(), 1)
        );
        $this->assertSame(['10/51' => 6], $this->landings($this->helper(), 2));
        $this->assertSame(['10/53' => 6, '11/53' => 5], $this->landings($this->helper(), 3));
    }

    public function testSingleBrandCategoryHasNoLandingsWhenSingleFiltersAreHidden(): void
    {
        $this->assertSame([], $this->landings($this->helper(), 4));
        $this->assertSame(['10/51' => 7], $this->landings($this->helper(['hide_single_filters' => 0]), 4));
    }

    public function testThreshold(): void
    {
        $this->assertSame(['10/51' => 6, '10/53' => 6], $this->landings($this->helper(['brand_feature_landings_min_products' => 6]), 1));
        $this->assertSame(['10/51' => 6, '10/52' => 3], $this->landings($this->helper(['brand_feature_landings_min_products' => 3]), 2));
    }

    public function testShare(): void
    {
        // 6/11 = 54.5 % > 50; 5/10 = 50 % — межа включна.
        $this->assertSame(['11/53' => 5], $this->landings($this->helper(['brand_feature_landings_max_share' => 50]), 3));
        $this->assertSame([], $this->landings($this->helper(['brand_feature_landings_max_share' => 50]), 2));
    }

    public function testNoFlaggedFeatureMeansNoQueries(): void
    {
        $products = $this->productsEntity();
        $helper = $this->helper([], $products);
        $features = $this->catalogFeatures(false);

        $this->assertFalse($helper->isLanding($this->category(1), $features, $this->filter([10], [5 => [51 => 'brush']])));
        $this->assertSame([], $helper->getCategoryLinks($this->category(1), $features, $this->filter()));
        $this->assertSame([], $helper->getBrandLinks($this->brand(10), $features, ['brand_id' => [10], 'visible' => 1]));
        $this->assertSame(0, $products->calls);
    }

    public function testIsLanding(): void
    {
        $helper = $this->helper();

        $this->assertTrue($helper->isLanding($this->category(1), $this->catalogFeatures(), $this->filter([10], [5 => [51 => 'brush']])));
        $this->assertTrue($helper->isLanding($this->category(1), $this->catalogFeatures(), $this->filter([10], [5 => [51 => 'brush']]) + ['page' => 2, 'limit' => 24]));
    }

    public static function notLandingCases(): array
    {
        $landing = ['brand_id' => [10], 'features' => [5 => [51 => 'brush']]];

        return [
            'без бренду' => [['features' => [5 => [51 => 'brush']]]],
            'без властивості' => [['brand_id' => [10]]],
            'два бренди' => [['brand_id' => [10, 11]] + $landing],
            'два значення' => [['features' => [5 => [51 => 'brush', 53 => 'filter']]] + $landing],
            'дві властивості' => [['features' => [5 => [51 => 'brush'], 6 => [61 => 'red']]] + $landing],
            'непозначена властивість' => [['features' => [6 => [61 => 'red']]] + $landing],
            'filter-*' => [$landing + ['other_filter' => ['discounted']]],
            'ціна' => [$landing + ['price' => ['min' => 1, 'max' => 100]]],
            'пошук' => [$landing + ['keyword' => 'brush']],
            'нижче порогу' => [['features' => [5 => [52 => 'motor']]] + $landing],
            'інший бренд нижче порогу' => [['brand_id' => [11]] + $landing],
        ];
    }

    #[DataProvider('notLandingCases')]
    public function testNotLanding(array $productsFilter): void
    {
        $this->assertFalse(
            $this->helper()->isLanding($this->category(1), $this->catalogFeatures(), $productsFilter + ['category_id' => self::TREE[1], 'visible' => 1])
        );
    }

    /** robots, canonical і блок посилань однієї сторінки — два запити на категорію, не більше. */
    public function testCountsAreQueriedOncePerCategory(): void
    {
        $products = $this->productsEntity();
        $helper = $this->helper([], $products);

        $helper->isLanding($this->category(1), $this->catalogFeatures(), $this->filter([10], [5 => [51 => 'brush']]));
        $helper->getCategoryLinks($this->category(1), $this->catalogFeatures(), $this->filter([10], [5 => [51 => 'brush']]));
        $helper->getCategoryLinks($this->category(1), $this->catalogFeatures(), $this->filter());

        $this->assertSame(2, $products->calls);
    }

    public function testCategoryLinks(): void
    {
        $helper = $this->helper();
        $features = $this->catalogFeatures();

        $this->assertSame(['1/acme/type-brush', '1/acme/type-filter', '1/bolt/type-filter'], $this->urls($helper->getCategoryLinks($this->category(1), $features, $this->filter())));
        $this->assertSame(['1/bolt/type-filter'], $this->urls($helper->getCategoryLinks($this->category(1), $features, $this->filter([11]))));
        // Посадкова: інші значення того ж бренду, потім те саме значення інших брендів; себе — ні.
        $this->assertSame(['1/acme/type-filter'], $this->urls($helper->getCategoryLinks($this->category(1), $features, $this->filter([10], [5 => [51 => 'brush']]))));
        $this->assertSame(['3/acme/type-filter'], $this->urls($helper->getCategoryLinks($this->category(3), $features, $this->filter([11], [5 => [53 => 'filter']]))));
    }

    public static function noLinksCases(): array
    {
        return [
            'властивість без бренду' => [['features' => [5 => [51 => 'brush']]]],
            'filter-*' => [['other_filter' => ['featured']]],
            'ціна' => [['price' => ['min' => 1, 'max' => 9]]],
            'пошук' => [['keyword' => 'a']],
            'два бренди' => [['brand_id' => [10, 11]]],
            'бренд і непозначена властивість' => [['brand_id' => [10], 'features' => [6 => [61 => 'red']]]],
        ];
    }

    #[DataProvider('noLinksCases')]
    public function testNoLinksOnOtherFilterPages(array $productsFilter): void
    {
        $this->assertSame(
            [],
            $this->helper()->getCategoryLinks($this->category(1), $this->catalogFeatures(), $productsFilter + ['category_id' => self::TREE[1], 'visible' => 1])
        );
    }

    public function testLinksAreLimited(): void
    {
        $products = [];
        for ($value = 100; $value < 130; $value++) {
            for ($i = 0; $i < 5; $i++) {
                $products[] = [10, [4], [$value]];
            }
        }
        $products[] = [11, [4], []];
        $helper = $this->helper([], $this->productsEntity($products), range(100, 129));

        $links = $helper->getCategoryLinks($this->category(4), $this->catalogFeatures(), $this->filter([], [], 4));

        $this->assertCount(BrandFeatureLandingsHelper::LINKS_LIMIT, $links);
    }

    /** Сторінка бренду бере лише листові категорії: їхні числа точні без окремого запиту на кожну. */
    public function testBrandLinks(): void
    {
        $links = $this->helper()->getBrandLinks($this->brand(10), $this->catalogFeatures(), ['brand_id' => [10], 'visible' => 1]);
        $this->assertSame(['2/acme/type-brush', '3/acme/type-filter'], $this->urls($links));
        $this->assertSame('Brush', $links[0]->value_name);
        $this->assertSame('Acme', $links[0]->brand_name);
        $this->assertSame('Category 2', $links[0]->category_name);
        $this->assertSame(6, $links[0]->products_count);

        $this->assertSame([], $this->helper()->getBrandLinks($this->brand(10), $this->catalogFeatures(), ['brand_id' => [10], 'visible' => 1, 'features' => [5 => [51 => 'brush']]]));
    }

    /** Інші бренди з тим самим значенням не витісняються значеннями свого бренду. */
    public function testLandingLinksKeepRoomForOtherBrands(): void
    {
        $products = [];
        for ($value = 100; $value < 130; $value++) {
            for ($i = 0; $i < 5; $i++) {
                $products[] = [10, [4], [$value]];
            }
        }
        for ($i = 0; $i < 5; $i++) {
            $products[] = [11, [4], [100]];
        }
        $products[] = [11, [4], []];
        $products[] = [11, [4], []];
        $helper = $this->helper([], $this->productsEntity($products), range(100, 129));

        $urls = $this->urls($helper->getCategoryLinks($this->category(4), $this->catalogFeatures(), $this->filter([10], [5 => [100 => 'v100']], 4)));

        $this->assertCount(BrandFeatureLandingsHelper::LINKS_LIMIT, $urls);
        $this->assertSame('4/bolt/type-v100', end($urls));
        $this->assertNotContains('4/acme/type-v100', $urls);
    }

    /** Сторінка бренду бере посадкові з різних категорій по черзі. */
    public function testBrandLinksAlternateCategories(): void
    {
        $products = [];
        foreach ([2 => [100, 101, 102], 3 => [110, 111]] as $category => $values) {
            foreach ($values as $value) {
                for ($i = 0; $i < 5; $i++) {
                    $products[] = [10, [$category], [$value]];
                }
            }
            $products[] = [11, [$category], []];
        }
        $helper = $this->helper([], $this->productsEntity($products), [100, 101, 102, 110, 111]);

        $this->assertSame(
            ['2/acme/type-v100', '3/acme/type-v110', '2/acme/type-v101', '3/acme/type-v111', '2/acme/type-v102'],
            $this->urls($helper->getBrandLinks($this->brand(10), $this->catalogFeatures(), ['brand_id' => [10], 'visible' => 1]))
        );
    }

    /** Батьківська категорія в одному запиті з листами дала б неточну кількість. */
    public function testBrandLinksQueryOnlyLeaves(): void
    {
        $products = $this->productsEntity();
        $this->helper([], $products)->getBrandLinks($this->brand(10), $this->catalogFeatures(), ['brand_id' => [10], 'visible' => 1]);

        $this->assertNotEmpty($products->scopes);
        foreach ($products->scopes as $scope) {
            $this->assertNotContains(1, $scope);
        }
    }

    public function testSitemapHasEveryQualifyingLandingOnce(): void
    {
        $this->assertSame(
            ['1/acme/type-brush', '1/acme/type-filter', '1/bolt/type-filter', '2/acme/type-brush', '3/acme/type-filter', '3/bolt/type-filter'],
            $this->urls($this->helper()->getSitemapLandings(), true)
        );
    }

    public function testSitemapSkipsCategoriesWithoutTheFeature(): void
    {
        $this->assertSame(
            ['2/acme/type-brush'],
            $this->urls($this->helper([], null, null, [5 => [2]])->getSitemapLandings(), true)
        );
    }

    public function testSitemapSkipsBranchesUnderAHiddenAncestor(): void
    {
        $this->assertSame(
            ['4/acme/type-brush'],
            $this->urls($this->helper(['hide_single_filters' => 0], null, null, null, [1 => false])->getSitemapLandings(), true)
        );
    }

    /** Значення властивості, не прив'язаної до категорії, дало б адресу з 404. */
    public function testSitemapSkipsFeaturesNotLinkedToTheCategory(): void
    {
        $products = [];
        foreach ([2, 3] as $category) {
            for ($i = 0; $i < 5; $i++) {
                $products[] = [10, [$category], [71]];
            }
            $products[] = [10, [$category], []];
            $products[] = [10, [$category], []];
            $products[] = [11, [$category], []];
        }

        $this->assertSame(
            ['3/acme/kind-k71'],
            $this->urls($this->helper([], $this->productsEntity($products), [71], [5 => [2], 7 => [3]])->getSitemapLandings(), true)
        );
    }

    public function testSitemapSkipsHiddenBranches(): void
    {
        $this->assertSame(
            ['1/acme/type-brush', '1/acme/type-filter', '1/bolt/type-filter', '3/acme/type-filter', '3/bolt/type-filter'],
            $this->urls($this->helper([], null, null, null, [2 => false])->getSitemapLandings(), true)
        );
    }

    /** @return array<string, int> «бренд/значення» => товарів */
    private function landings(BrandFeatureLandingsHelper $helper, int $categoryId): array
    {
        $result = [];
        foreach ($helper->getCategoryLandings($this->category($categoryId), $this->catalogFeatures()) as $landing) {
            $result[$landing->brand_id . '/' . $landing->value_id] = $landing->products_count;
        }

        return $result;
    }

    private function urls(array $links, bool $sort = false): array
    {
        $urls = array_map(static fn(object $l) => $l->url, $links);
        if ($sort) {
            sort($urls);
        }

        return $urls;
    }

    private function filter(array $brands = [], array $features = [], int $categoryId = 1): array
    {
        $filter = ['category_id' => self::TREE[$categoryId], 'visible' => 1];
        if ($brands) {
            $filter['brand_id'] = $brands;
        }
        if ($features) {
            $filter['features'] = $features;
        }

        return $filter;
    }

    private function catalogFeatures(bool $flagged = true): array
    {
        return [
            5 => (object)['id' => 5, 'url' => 'type', 'name' => 'Type', 'index_with_brand' => (int)$flagged],
            6 => (object)['id' => 6, 'url' => 'color', 'name' => 'Color', 'index_with_brand' => 0],
        ];
    }

    private function category(int $id, array $visible = []): object
    {
        $path = [];
        foreach (array_filter([self::PARENT[$id] ?? null, $id]) as $pathId) {
            $path[] = (object)['id' => $pathId, 'visible' => $visible[$pathId] ?? true];
        }

        return (object)[
            'id' => $id,
            'url' => (string)$id,
            'name' => 'Category ' . $id,
            'visible' => $visible[$id] ?? true,
            'children' => self::TREE[$id],
            'path' => $path,
        ];
    }

    private function brand(int $id): object
    {
        return (object)['id' => $id, 'name' => [10 => 'Acme', 11 => 'Bolt'][$id], 'url' => [10 => 'acme', 11 => 'bolt'][$id], 'visible' => 1];
    }

    /** @return array<int, array{0: int, 1: int[], 2: int[]}> бренд, категорії, значення */
    private static function catalog(): array
    {
        $products = [];
        $add = static function (int $count, int $brand, array $categories, array $values) use (&$products) {
            for ($i = 0; $i < $count; $i++) {
                $products[] = [$brand, $categories, $values];
            }
        };
        $add(5, 10, [2], [51]);
        $add(3, 10, [2], [52]);
        $add(2, 11, [2], [51]);
        $add(2, 10, [2], []);
        $add(6, 10, [3], [53]);
        $add(4, 10, [3], []);
        $add(5, 11, [3], [53]);
        $add(5, 11, [3], []);
        $add(1, 10, [2, 3], [51]);
        $add(7, 10, [4], [51]);
        $add(3, 10, [4], []);

        return $products;
    }

    private function productsEntity(?array $products = null): object
    {
        $products ??= self::catalog();
        $valueFeature = static fn(int $valueId) => [61 => 6, 71 => 7][$valueId] ?? 5;

        return new class($products, $valueFeature) {
            public int $calls = 0;
            public array $scopes = [];

            public function __construct(private array $products, private \Closure $valueFeature)
            {
            }

            public function __call(string $name, array $arguments): static
            {
                return $this;
            }

            public function countGroupedByBrand(array $filter, array $featuresIds = [], bool $perCategory = false): array
            {
                $this->calls++;
                $this->scopes[] = array_values((array)$filter['category_id']);
                $sets = [];
                foreach ($this->products as $id => [$brand, $categories, $values]) {
                    $inScope = array_intersect($categories, (array)$filter['category_id']);
                    if (!$inScope || (isset($filter['brand_id']) && !in_array($brand, (array)$filter['brand_id'], true))) {
                        continue;
                    }
                    foreach ($perCategory ? $inScope : [null] as $category) {
                        if (empty($featuresIds)) {
                            $sets[json_encode([$category, $brand, null])][$id] = true;
                            continue;
                        }
                        foreach ($values as $value) {
                            if (in_array(($this->valueFeature)($value), $featuresIds, true)) {
                                $sets[json_encode([$category, $brand, $value])][$id] = true;
                            }
                        }
                    }
                }

                $rows = [];
                foreach ($sets as $key => $ids) {
                    [$category, $brand, $value] = json_decode($key);
                    $row = ['brand_id' => (string)$brand, 'count' => (string)count($ids)];
                    if ($perCategory) {
                        $row['category_id'] = (string)$category;
                    }
                    if (!empty($featuresIds)) {
                        $row['feature_id'] = (string)($this->valueFeature)($value);
                        $row['value_id'] = (string)$value;
                    }
                    $rows[] = (object)$row;
                }

                return $rows;
            }
        };
    }

    private function helper(
        array $settings = [],
        ?object $products = null,
        ?array $extraValues = null,
        ?array $featureCategories = null,
        array $visible = []
    ): BrandFeatureLandingsHelper {
        $settings += ['hide_single_filters' => 1];
        $settingsStub = $this->createStub(Settings::class);
        $settingsStub->method('get')->willReturnCallback(static fn($param) => $settings[$param] ?? null);

        $values = [
            51 => (object)['id' => 51, 'feature_id' => 5, 'value' => 'Brush', 'translit' => 'brush'],
            52 => (object)['id' => 52, 'feature_id' => 5, 'value' => 'Motor', 'translit' => 'motor'],
            53 => (object)['id' => 53, 'feature_id' => 5, 'value' => 'Filter', 'translit' => 'filter'],
            61 => (object)['id' => 61, 'feature_id' => 6, 'value' => 'Red', 'translit' => 'red'],
        ];
        foreach ($extraValues ?? [] as $id) {
            $values[$id] = $id === 71
                ? (object)['id' => $id, 'feature_id' => 7, 'value' => 'K71', 'translit' => 'k71']
                : (object)['id' => $id, 'feature_id' => 5, 'value' => 'V' . $id, 'translit' => 'v' . $id];
        }

        $categories = [];
        foreach (array_keys(self::TREE) as $id) {
            $categories[$id] = $this->category($id, $visible);
        }

        $entities = [
            ProductsEntity::class => $products ?? $this->productsEntity(),
            FeaturesValuesEntity::class => new FakeEntity(fn(array $filter) => array_intersect_key($values, array_flip((array)$filter['id']))),
            BrandsEntity::class => new FakeEntity(fn(array $filter) => array_intersect_key(
                [10 => $this->brand(10), 11 => $this->brand(11)],
                array_flip((array)$filter['id'])
            )),
            CategoriesEntity::class => new FakeEntity(fn(array $filter) => $categories, fn($id) => $categories[$id] ?? null),
            FeaturesEntity::class => new FakeEntity(
                fn(array $filter) => [
                    5 => $this->catalogFeatures()[5],
                    7 => (object)['id' => 7, 'url' => 'kind', 'name' => 'Kind', 'index_with_brand' => 1],
                ],
                null,
                fn($featureId) => ($featureCategories ?? [5 => [1, 2, 3, 4], 6 => [2]])[$featureId] ?? []
            ),
        ];

        $factory = $this->createStub(EntityFactory::class);
        $factory->method('get')->willReturnCallback(static fn(string $class) => $entities[$class]);

        return new class($factory, $settingsStub) extends BrandFeatureLandingsHelper {
            protected function landingUrl(object $category, object $brand, object $feature, object $value, bool $absolute): string
            {
                return "{$category->url}/{$brand->url}/{$feature->url}-{$value->translit}";
            }
        };
    }
}

/** Сутність-заглушка: ланцюжки mappedBy()/noLimit() повертають її саму. */
class FakeEntity
{
    public function __construct(private \Closure $find, private ?\Closure $get = null, private ?\Closure $featureCategories = null)
    {
    }

    public function __call(string $name, array $arguments): static
    {
        return $this;
    }

    public function find(array $filter = []): array
    {
        return ($this->find)($filter);
    }

    public function get($id): ?object
    {
        return ($this->get)($id);
    }

    public function getFeatureCategories($featureId): array
    {
        return ($this->featureCategories)($featureId);
    }
}
