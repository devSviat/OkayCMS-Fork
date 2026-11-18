<?php


namespace Okay\Helpers;


use Okay\Core\EntityFactory;
use Okay\Core\Modules\Extender\ExtenderFacade;
use Okay\Core\Router;
use Okay\Core\Settings;
use Okay\Entities\BrandsEntity;
use Okay\Entities\CategoriesEntity;
use Okay\Entities\FeaturesEntity;
use Okay\Entities\FeaturesValuesEntity;
use Okay\Entities\ProductsEntity;

/**
 * Посадкові сторінки «бренд + значення властивості» в категорії: /catalog/x/brand-y/feature-value.
 *
 * Властивість бере участь, лише коли в її картці ввімкнено index_with_brand. Пара (бренд, значення)
 * кваліфікується, коли товарів не менше за поріг і вони не займають більше заданої частки товарів
 * пари «категорія × бренд». Robots, canonical, блок посилань і карта сайту беруть той самий набір.
 * Докладно — docs/UPGRADE-brand-feature-landings.md.
 */
class BrandFeatureLandingsHelper
{
    public const int DEFAULT_MIN_PRODUCTS = 5;
    public const int DEFAULT_MAX_SHARE = 80;
    public const int LINKS_LIMIT = 24;

    /** Межі, у яких адмінка зберігає налаштування; поза ними діє типове значення. */
    public const array SETTINGS_MAX = [
        'brand_feature_landings_min_products' => 100000,
        'brand_feature_landings_max_share' => 100,
    ];

    /** @var EntityFactory */
    private $entityFactory;

    /** @var Settings */
    private $settings;

    /** @var array<int, object[]> кваліфіковані посадкові за id категорії в межах запиту */
    private array $categoryLandings = [];

    public function __construct(EntityFactory $entityFactory, Settings $settings)
    {
        $this->entityFactory = $entityFactory;
        $this->settings = $settings;
    }

    public function getMinProducts(): int
    {
        $value = $this->positiveSetting('brand_feature_landings_min_products') ?? self::DEFAULT_MIN_PRODUCTS;

        return ExtenderFacade::execute(__METHOD__, $value, func_get_args());
    }

    /** Відсоток товарів пари «категорія × бренд», понад який сторінка дублює бренд-фільтр. */
    public function getMaxShare(): int
    {
        $value = $this->positiveSetting('brand_feature_landings_max_share') ?? self::DEFAULT_MAX_SHARE;

        return ExtenderFacade::execute(__METHOD__, $value, func_get_args());
    }

    /**
     * Позначені властивості серед переданих.
     *
     * @param object[] $features властивості каталогу (in_filter, visible)
     * @return array<int, object> за id
     */
    public function getLandingFeatures(array $features): array
    {
        $result = [];
        foreach ($features as $feature) {
            if (!empty($feature->index_with_brand)) {
                $result[(int)$feature->id] = $feature;
            }
        }

        return ExtenderFacade::execute(__METHOD__, $result, func_get_args());
    }

    /**
     * Кваліфіковані посадкові категорії (з піддеревом), від більших до менших.
     * Два запити на категорію за весь запит сторінки.
     *
     * @param object[] $catalogFeatures властивості категорії, як у CategoryController
     * @return object[] category_id, brand_id, feature_id, translit, value_id, products_count, pair_products_count
     */
    public function getCategoryLandings(object $category, array $catalogFeatures): array
    {
        $categoryId = (int)$category->id;
        $featuresIds = array_keys($this->getLandingFeatures($catalogFeatures));

        if (empty($featuresIds) || empty($category->children)) {
            $result = [];
        } elseif (isset($this->categoryLandings[$categoryId])) {
            $result = $this->categoryLandings[$categoryId];
        } else {
            $result = $this->countLandings([$categoryId => $category], null, array_fill_keys($featuresIds, [$categoryId]));
            $this->categoryLandings[$categoryId] = $result;
        }

        return ExtenderFacade::execute(__METHOD__, $result, func_get_args());
    }

    /**
     * Чи сторінка категорії — кваліфікована посадкова: рівно один бренд, одна позначена властивість
     * з одним значенням, без filter-*, ціни й пошуку. Сторінка й сортування не заважають.
     *
     * @param array $productsFilter фільтр товарів сторінки (brand_id, features, other_filter, price, keyword)
     */
    public function isLanding(object $category, array $catalogFeatures, array $productsFilter): bool
    {
        $result = ($pair = $this->landingPair($productsFilter)) !== null
            && $this->findLanding($this->getCategoryLandings($category, $catalogFeatures), ...$pair) !== null;

        return ExtenderFacade::execute(__METHOD__, $result, func_get_args());
    }

    /**
     * Посилання на посадкові для сторінки категорії: без фільтрів — найбільші посадкові категорії;
     * бренд-фільтр — посадкові цього бренду; посадкова — інші значення того ж бренду, потім те саме
     * значення інших брендів (до третини блоку). На інших сторінках фільтра — нічого.
     *
     * @return object[] url, value_name, brand_name, feature_name, category_name, products_count
     */
    public function getCategoryLinks(object $category, array $catalogFeatures, array $productsFilter): array
    {
        $result = [];

        $brandsIds = array_map(intval(...), (array)($productsFilter['brand_id'] ?? []));
        $features = $productsFilter['features'] ?? [];
        $selection = [];

        if (empty($this->getLandingFeatures($catalogFeatures)) || !$this->isPlainFilter($productsFilter) || count($brandsIds) > 1) {
            $selection = null;
        } elseif (empty($features)) {
            $selection = $this->getCategoryLandings($category, $catalogFeatures);
            if (!empty($brandsIds)) {
                $selection = array_filter($selection, static fn(object $l) => $l->brand_id === $brandsIds[0]);
            }
        } elseif (($pair = $this->landingPair($productsFilter)) !== null) {
            [$brandId, $featureId, $translit] = $pair;
            $landings = $this->getCategoryLandings($category, $catalogFeatures);
            if ($this->findLanding($landings, $brandId, $featureId, $translit) !== null) {
                $sameBrand = array_filter($landings, static fn(object $l) => $l->brand_id === $brandId && $l->translit !== $translit);
                $sameValue = array_slice(
                    array_values(array_filter($landings, static fn(object $l) => $l->translit === $translit && $l->brand_id !== $brandId)),
                    0,
                    intdiv(self::LINKS_LIMIT, 3)
                );
                $selection = array_merge(array_slice(array_values($sameBrand), 0, self::LINKS_LIMIT - count($sameValue)), $sameValue);
            }
        }

        if (!empty($selection)) {
            $result = $this->buildLinks(
                array_slice(array_values($selection), 0, self::LINKS_LIMIT),
                $this->getLandingFeatures($catalogFeatures),
                false
            );
        }

        return ExtenderFacade::execute(__METHOD__, $result, func_get_args());
    }

    /**
     * Посилання на посадкові бренду для /brand/x без фільтрів. Беруться лише листові категорії:
     * один запит дає точні числа для всіх, а підсумок батьківської з листів не складається
     * (товар у двох листах рахувався б двічі).
     *
     * @param object[] $catalogFeatures властивості каталогу бренду
     * @return object[] як у getCategoryLinks()
     */
    public function getBrandLinks(object $brand, array $catalogFeatures, array $productsFilter): array
    {
        $result = [];

        $features = $this->getLandingFeatures($catalogFeatures);
        if (!empty($features) && $this->isPlainFilter($productsFilter) && empty($productsFilter['features'])) {
            $featuresByCategory = $this->featuresCategories(array_keys($features));
            $leaves = array_filter(
                $this->reachableCategories($featuresByCategory),
                static fn(object $c) => count((array)$c->children) === 1
            );

            $landings = $this->interleaveCategories($this->countLandings($leaves, (int)$brand->id, $featuresByCategory));
            $result = $this->buildLinks(array_slice($landings, 0, self::LINKS_LIMIT), $features, false);
        }

        return ExtenderFacade::execute(__METHOD__, $result, func_get_args());
    }

    /**
     * Усі кваліфіковані посадкові поточної мови з абсолютними адресами — для карти сайту.
     * Листові категорії рахуються двома запитами на всі, батьківські — двома на кожну.
     *
     * @return object[] як у getCategoryLinks()
     */
    public function getSitemapLandings(): array
    {
        $result = [];

        /** @var FeaturesEntity $featuresEntity */
        $featuresEntity = $this->entityFactory->get(FeaturesEntity::class);
        $features = [];
        foreach ($featuresEntity->noLimit()->find(['index_with_brand' => 1, 'in_filter' => 1, 'visible' => 1]) as $feature) {
            $features[(int)$feature->id] = $feature;
        }

        if (!empty($features)) {
            $featuresByCategory = $this->featuresCategories(array_keys($features));

            $leaves = [];
            $landings = [];
            foreach ($this->reachableCategories($featuresByCategory) as $id => $category) {
                if (count((array)$category->children) === 1) {
                    $leaves[$id] = $category;
                } else {
                    $landings = array_merge($landings, $this->countLandings([$id => $category], null, $featuresByCategory));
                }
            }
            $landings = array_merge($landings, $this->countLandings($leaves, null, $featuresByCategory));

            $result = $this->buildLinks($landings, $features, true);
        }

        return ExtenderFacade::execute(__METHOD__, $result, func_get_args());
    }

    /**
     * Умови товарів для підрахунку — ті самі, що в paginate() сторінки категорії.
     *
     * @param int[] $categoriesIds
     */
    public function getCountFilter(array $categoriesIds): array
    {
        $filter = [
            'category_id' => array_values($categoriesIds),
            'visible' => 1,
        ];
        if ($this->settings->get('missing_products') === MISSING_PRODUCTS_HIDE) {
            $filter['in_stock'] = true;
        }

        return ExtenderFacade::execute(__METHOD__, $filter, func_get_args());
    }

    /**
     * Єдина точка, через яку проходить кваліфікований набір: robots, canonical, посилання й карта
     * сайту беруть уже відфільтрований результат, тож пара, прибрана розширенням, зникає скрізь.
     *
     * @param object[] $landings як у getCategoryLandings()
     * @return object[]
     */
    public function filterLandings(array $landings): array
    {
        return ExtenderFacade::execute(__METHOD__, $landings, func_get_args());
    }

    protected function landingUrl(object $category, object $brand, object $feature, string $translit, bool $absolute): string
    {
        return Router::generateUrl('category', [
            'url' => $category->url,
            'filtersUrl' => ['brand' => $brand->url, $feature->url => $translit],
        ], $absolute);
    }

    /**
     * Одна категорія — її піддерево одним підсумком; кілька — лише листи, згруповані за категорією.
     *
     * @param array<int, object> $categories
     * @param array<int, int[]> $featuresByCategory id властивості => id категорій, де вона є
     * @return object[]
     */
    private function countLandings(array $categories, ?int $brandId, array $featuresByCategory): array
    {
        if (empty($categories)) {
            return [];
        }

        $perCategory = count($categories) > 1;
        $scope = $perCategory ? array_keys($categories) : (array)reset($categories)->children;
        $filter = $this->getCountFilter(array_map(intval(...), $scope));

        $valuesFilter = $filter;
        if ($brandId !== null) {
            $valuesFilter['brand_id'] = [$brandId];
        }

        /** @var ProductsEntity $productsEntity */
        $productsEntity = $this->entityFactory->get(ProductsEntity::class);

        $values = [];
        foreach ($productsEntity->countGroupedByBrand($valuesFilter, array_keys($featuresByCategory), $perCategory) as $row) {
            $categoryId = $perCategory ? (int)$row->category_id : (int)array_key_first($categories);
            if (in_array($categoryId, $featuresByCategory[(int)$row->feature_id] ?? [], true)) {
                $values[$categoryId][] = $row;
            }
        }

        if (empty($values)) {
            return [];
        }

        if ($perCategory) {
            $filter = $this->getCountFilter(array_keys($values));
        }

        $pairs = [];
        foreach ($productsEntity->countGroupedByBrand($filter, [], $perCategory) as $row) {
            $categoryId = $perCategory ? (int)$row->category_id : (int)array_key_first($categories);
            $pairs[$categoryId][(int)$row->brand_id] = (int)$row->count;
        }

        $landings = [];
        foreach ($values as $categoryId => $rows) {
            $landings = array_merge($landings, $this->qualify($categoryId, $rows, $pairs[$categoryId] ?? []));
        }
        usort($landings, [$this, 'compareLandings']);

        return $this->filterLandings($landings);
    }

    /**
     * @param object[] $rows підрахунки «бренд × значення» однієї категорії
     * @param array<int, int> $pairs товарів за брендом у тій самій категорії
     * @return object[]
     */
    private function qualify(int $categoryId, array $rows, array $pairs): array
    {
        // Єдиний бренд категорії CategoryController не показує у фільтрі й віддає 404 на brand-x.
        if ($this->settings->get('hide_single_filters') && count($pairs) <= 1) {
            return [];
        }

        $minProducts = $this->getMinProducts();
        $maxShare = $this->getMaxShare();

        $result = [];
        foreach ($rows as $row) {
            $count = (int)$row->count;
            $pairCount = $pairs[(int)$row->brand_id] ?? 0;
            // Без translit адреса посадкової не будується, а лістинг такого значення не знайде.
            if ((string)$row->translit !== '' && $count >= $minProducts && $pairCount > 0 && $count * 100 <= $maxShare * $pairCount) {
                $result[] = (object)[
                    'category_id' => $categoryId,
                    'brand_id' => (int)$row->brand_id,
                    'feature_id' => (int)$row->feature_id,
                    'translit' => (string)$row->translit,
                    'value_id' => (int)$row->value_id,
                    'products_count' => $count,
                    'pair_products_count' => $pairCount,
                ];
            }
        }

        return $result;
    }

    /**
     * По одній посадковій з кожної категорії по черзі, щоб блок не заповнила найбільша категорія.
     *
     * @param object[] $landings відсортовані compareLandings()
     * @return object[]
     */
    private function interleaveCategories(array $landings): array
    {
        $byCategory = [];
        foreach ($landings as $landing) {
            $byCategory[$landing->category_id][] = $landing;
        }

        $result = [];
        for ($i = 0; count($result) < count($landings); $i++) {
            foreach ($byCategory as $categoryLandings) {
                if (isset($categoryLandings[$i])) {
                    $result[] = $categoryLandings[$i];
                }
            }
        }

        return $result;
    }

    private function compareLandings(object $a, object $b): int
    {
        return [$b->products_count, $a->category_id, $a->brand_id, $a->value_id]
            <=> [$a->products_count, $b->category_id, $b->brand_id, $b->value_id];
    }

    /**
     * @param object[] $landings
     * @param array<int, object> $features за id
     * @return object[]
     */
    private function buildLinks(array $landings, array $features, bool $absolute): array
    {
        if (empty($landings)) {
            return [];
        }

        /** @var FeaturesValuesEntity $featuresValuesEntity */
        $featuresValuesEntity = $this->entityFactory->get(FeaturesValuesEntity::class);
        /** @var BrandsEntity $brandsEntity */
        $brandsEntity = $this->entityFactory->get(BrandsEntity::class);
        /** @var CategoriesEntity $categoriesEntity */
        $categoriesEntity = $this->entityFactory->get(CategoriesEntity::class);

        $values = $featuresValuesEntity->noLimit()->mappedBy('id')->find([
            'id' => array_values(array_unique(array_column($landings, 'value_id'))),
        ]);
        $brands = $brandsEntity->noLimit()->mappedBy('id')->find([
            'id' => array_values(array_unique(array_column($landings, 'brand_id'))),
        ]);

        /** @var array<string, object> $links за url: однакові адреси дають одне посилання */
        $links = [];
        foreach ($landings as $landing) {
            $value = $values[$landing->value_id] ?? null;
            $brand = $brands[$landing->brand_id] ?? null;
            $feature = $features[$landing->feature_id] ?? null;
            $category = $categoriesEntity->get($landing->category_id);

            // Без будь-якої з частин адреса посадкової не будується.
            if ($value === null || empty($brand->url) || empty($feature->url) || empty($category->url)) {
                continue;
            }

            $url = $this->landingUrl($category, $brand, $feature, $landing->translit, $absolute);
            if (isset($links[$url])) {
                continue;
            }

            $links[$url] = (object)[
                'url' => $url,
                'value_name' => $value->value,
                'brand_name' => $brand->name,
                'feature_name' => $feature->name,
                'category_name' => $category->name,
                'products_count' => $landing->products_count,
            ];
        }

        return array_values($links);
    }

    /**
     * @return array{0: int, 1: int, 2: string}|null бренд, властивість, translit значення
     */
    private function landingPair(array $productsFilter): ?array
    {
        $brandsIds = (array)($productsFilter['brand_id'] ?? []);
        $features = $productsFilter['features'] ?? [];

        if (!$this->isPlainFilter($productsFilter) || count($brandsIds) !== 1 || count($features) !== 1) {
            return null;
        }

        $values = (array)reset($features);
        if (count($values) !== 1) {
            return null;
        }

        return [(int)reset($brandsIds), (int)array_key_first($features), (string)reset($values)];
    }

    /** За translit, як лістинг: значення з однаковим translit — та сама сторінка. */
    private function findLanding(array $landings, int $brandId, int $featureId, string $translit): ?object
    {
        foreach ($landings as $landing) {
            if ($landing->brand_id === $brandId && $landing->feature_id === $featureId && $landing->translit === $translit) {
                return $landing;
            }
        }

        return null;
    }

    /** Без filter-*, ціни й пошуку. */
    private function isPlainFilter(array $productsFilter): bool
    {
        return empty($productsFilter['other_filter']) && empty($productsFilter['price']) && empty($productsFilter['keyword']);
    }

    /**
     * @param int[] $featuresIds
     * @return array<int, int[]> id властивості => id категорій, до яких вона прив'язана
     */
    private function featuresCategories(array $featuresIds): array
    {
        /** @var FeaturesEntity $featuresEntity */
        $featuresEntity = $this->entityFactory->get(FeaturesEntity::class);

        $result = [];
        foreach ($featuresIds as $featureId) {
            $result[(int)$featureId] = array_map(intval(...), (array)$featuresEntity->getFeatureCategories($featureId));
        }

        return $result;
    }

    /**
     * Видимі категорії з видимими предками, до яких прив'язана хоч одна з властивостей.
     *
     * @param array<int, int[]> $featuresByCategory
     * @return array<int, object>
     */
    private function reachableCategories(array $featuresByCategory): array
    {
        $withFeature = array_flip(array_merge(...array_values($featuresByCategory)));

        /** @var CategoriesEntity $categoriesEntity */
        $categoriesEntity = $this->entityFactory->get(CategoriesEntity::class);

        $result = [];
        foreach ($categoriesEntity->find() as $category) {
            if (!isset($withFeature[(int)$category->id]) || empty($category->visible) || empty($category->children)) {
                continue;
            }
            foreach ((array)($category->path ?? []) as $ancestor) {
                if (empty($ancestor->visible)) {
                    continue 2;
                }
            }
            $result[(int)$category->id] = $category;
        }

        return $result;
    }

    private function positiveSetting(string $name): ?int
    {
        $value = $this->settings->get($name);
        if (!is_numeric($value) || (int)$value < 1 || (int)$value > self::SETTINGS_MAX[$name]) {
            return null;
        }

        return (int)$value;
    }
}
