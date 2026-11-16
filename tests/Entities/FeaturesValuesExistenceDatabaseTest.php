<?php

namespace Entities;

use Aura\Sql\ExtendedPdo;
use Okay\Core\Config;
use Okay\Core\EntityFactory;
use Okay\Core\Modules\AbstractModuleEntityFilter;
use Okay\Core\Modules\ModulesEntitiesFilters;
use Okay\Core\ServiceLocator;
use Okay\Entities\FeaturesValuesEntity;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Вибірка значень властивостей на живій базі: порядок, наявність і видимість
 * рахуються SQL-ом, який мок не перевіряє. Тест іде проти MariaDB і
 * пропускається, де її немає (CI без бази).
 *
 * Фікстура створюється в транзакції й відкочується, тож база лишається як була.
 *
 * Еталон — той самий find(), відправлений старим шляхом порожнім модульним
 * фільтром: так еталон міняється разом зі старим шляхом, а не застигає в
 * рукописному SQL.
 */
class FeaturesValuesExistenceDatabaseTest extends TestCase
{
    private const PROBE = 'legacy_path_probe';

    private ExtendedPdo $pdo;
    private string $px;

    /** @var array<string, int> */
    private array $ids = [];

    /** З'єднання ліниве: без бази inTransaction() у tearDown() кинув би той самий PDOException, що й setUp(). */
    private bool $inTransaction = false;

    protected function setUp(): void
    {
        try {
            $this->pdo = ServiceLocator::getInstance()->getService(ExtendedPdo::class);
            $this->pdo->query('SELECT 1');
        } catch (\PDOException $e) {
            $this->markTestSkipped('немає бази: ' . $e->getMessage());
        }

        $prefix = (string) ServiceLocator::getInstance()->getService(Config::class)->get('db_prefix');
        if (!preg_match('/^[a-z0-9_]*$/i', $prefix)) {
            $this->fail('підозрілий префікс таблиць');
        }
        $this->px = $prefix;

        $this->pdo->beginTransaction();
        $this->inTransaction = true;
        $this->buildFixture();

        $this->modulesFilters()->registerFilter(FeaturesValuesEntity::class, self::PROBE, LegacyPathProbeFilter::class, 'apply');
    }

    protected function tearDown(): void
    {
        if ($this->inTransaction && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }

        // Реєстр модульних фільтрів — спільний сервіс процесу, тож пробник не має пережити тест.
        $registry = new \ReflectionProperty(ModulesEntitiesFilters::class, 'modulesFilters');
        $filters = $registry->getValue($this->modulesFilters());
        unset($filters[FeaturesValuesEntity::class][self::PROBE]);
        $registry->setValue($this->modulesFilters(), $filters);
    }

    public function testLegacyProbeReallyForcesOldPath(): void
    {
        $filter = ['feature_id' => $this->featureIds(), 'in_stock' => true];

        $existence = $this->findSql($filter);
        $legacy = $this->findSql($filter + [self::PROBE => true]);

        self::assertStringContainsString('pfe', $existence);
        self::assertDoesNotMatchRegularExpression('/AS `?pf`?\s/', $existence);
        self::assertMatchesRegularExpression('/AS `?pf`?\s/', $legacy);
        self::assertMatchesRegularExpression('/AS `?p`?\s/', $legacy);
        self::assertStringNotContainsString('pfe', $legacy);
    }

    /** @return array<string, array{bool}> */
    public static function categoryProvider(): array
    {
        return ['без категорії' => [false], 'з категорією' => [true]];
    }

    #[DataProvider('categoryProvider')]
    public function testInStockMatchesLegacyPath(bool $withCategory): void
    {
        $filter = ['feature_id' => $this->featureIds(), 'in_stock' => true];
        if ($withCategory) {
            $filter['category_id'] = [$this->ids['category_parent'], $this->ids['category_child']];
        }

        $found = $this->foundIds($filter);

        self::assertSame($this->foundIds($filter + [self::PROBE => true]), $found);
        self::assertContains($this->ids['value_in_stock'], $found);
        self::assertContains($this->ids['value_null_stock'], $found);
        self::assertNotContains($this->ids['value_no_stock'], $found, 'без умови на варіанти фільтр нічого не відсікає');
    }

    /** filter__in_stock умикає умову самою присутністю ключа, тож false фільтрує так само, як true. */
    public function testInStockFalseStillFilters(): void
    {
        $filter = ['feature_id' => $this->featureIds(), 'in_stock' => true];
        $expected = $this->foundIds($filter);

        $filter['in_stock'] = false;
        self::assertSame($expected, $this->foundIds($filter), 'новий режим');
        self::assertSame($expected, $this->foundIds($filter + [self::PROBE => true]), 'еталон');
    }

    /**
     * Властивість own призначено лише батьківській категорії, а товар з echo лежить
     * в іншій. Обидва набори враховано — лишається рівно echo; якщо один bind
     * перезаписав інший, результат порожній або без echo.
     */
    public function testCategoryBindsDoNotCollide(): void
    {
        $found = $this->foundIds([
            'feature_id' => $this->featureIds(),
            'category_id' => [$this->ids['category_parent']],
            'have_products_in_categories' => [$this->ids['category_other']],
            self::PROBE => true,
        ]);

        self::assertSame([$this->ids['value_other_cat']], $found);
    }

    /** Рядок pf без товару: старий шлях без p його не бачить, тож і підзапит мусить іти через pe. */
    public function testOrphanLinkMatchesLegacyPath(): void
    {
        $filter = ['feature_id' => $this->featureIds(), 'in_stock' => true];

        $found = $this->foundIds($filter);

        self::assertNotContains($this->ids['value_orphan'], $found);
        self::assertNotContains($this->ids['value_unused'], $found);
        self::assertSame($this->foundIds($filter + [self::PROBE => true]), $found);
    }

    /**
     * Кожна непорожня підмножина умов фасету, з категорією й без, з різними
     * значеннями visible та in_stock: множина й порядок збігаються зі старим шляхом.
     *
     * @return array<string, array{array<string, mixed>, bool}>
     */
    public static function facetProvider(): array
    {
        $variants = [
            'visible' => ['visible=1' => 1, 'visible=0' => 0],
            'in_stock' => ['in_stock=true' => true, 'in_stock=false' => false],
            'have_products_in_categories' => ['hpc' => true],
        ];

        $cases = [];
        $names = array_keys($variants);
        for ($mask = 1; $mask < 1 << count($names); $mask++) {
            $combos = [[]];
            foreach ($names as $bit => $name) {
                if (!($mask & 1 << $bit)) {
                    continue;
                }
                $next = [];
                foreach ($combos as $combo) {
                    foreach ($variants[$name] as $label => $value) {
                        $next[] = $combo + [$label => [$name, $value]];
                    }
                }
                $combos = $next;
            }
            foreach ($combos as $combo) {
                $conditions = [];
                foreach ($combo as [$name, $value]) {
                    $conditions[$name] = $value;
                }
                foreach (self::categoryProvider() as $categoryLabel => [$withCategory]) {
                    $cases[implode(', ', array_keys($combo)) . ', ' . $categoryLabel] = [$conditions, $withCategory];
                }
            }
        }

        return $cases;
    }

    /** @param array<string, mixed> $conditions */
    #[DataProvider('facetProvider')]
    public function testFacetMatchesLegacyPath(array $conditions, bool $withCategory): void
    {
        $filter = ['feature_id' => $this->featureIds()] + $conditions;
        if (isset($filter['have_products_in_categories'])) {
            $filter['have_products_in_categories'] = $this->categoryTree();
        }
        if ($withCategory) {
            $filter['category_id'] = $this->categoryTree();
        }

        $legacy = $this->foundIds($filter + [self::PROBE => true]);

        self::assertStringContainsString('pfe', $this->findSql($filter), 'фільтр не потрапив у режим існування');
        self::assertNotSame([], $legacy, 'порожній еталон нічого не розрізняє');
        self::assertSame($legacy, $this->foundIds($filter));
    }

    /**
     * Старий шлях вимагає, щоб усі умови виконав той самий товар. Окремі підзапити
     * на кожну умову знайшли б «якийсь видимий» і «якийсь у наявності» товар.
     */
    public function testAllConditionsMustHoldForOneProduct(): void
    {
        $filters = [
            'visible + in_stock' => ['visible' => 1, 'in_stock' => true],
            'visible + категорії' => ['visible' => 1, 'have_products_in_categories' => [$this->ids['category_parent']]],
            'in_stock + категорії' => ['in_stock' => true, 'have_products_in_categories' => [$this->ids['category_parent']]],
            'усі три' => ['visible' => 1, 'in_stock' => true, 'have_products_in_categories' => [$this->ids['category_parent']]],
        ];

        foreach ($filters as $label => $conditions) {
            $filter = ['feature_id' => $this->featureIds()] + $conditions;
            $found = $this->foundIds($filter);

            if (isset($conditions['visible'], $conditions['in_stock'])) {
                self::assertNotContains($this->ids['value_split_flags'], $found, $label);
            }
            if (isset($conditions['have_products_in_categories'])) {
                self::assertNotContains($this->ids['value_split_category'], $found, $label);
            }
            self::assertSame($this->foundIds($filter + [self::PROBE => true]), $found, $label);
        }
    }

    /**
     * Мала категорія лишається на старому шляху, велика йде в режим існування,
     * і обидві дають ту саму вибірку, що й еталон.
     */
    public function testCategorySizeChoosesPathWithSameResult(): void
    {
        $small = ['feature_id' => $this->featureIds(), 'visible' => 1, 'have_products_in_categories' => [$this->ids['category_other']]];
        $big = ['feature_id' => $this->featureIds(), 'visible' => 1, 'have_products_in_categories' => $this->categoryTree()];

        self::assertStringNotContainsString('pfe', $this->findSql($small), 'мала категорія пішла в режим існування');
        self::assertStringContainsString('pfe', $this->findSql($big), 'велика категорія лишилась на старому шляху');

        self::assertSame([$this->ids['value_other_cat']], $this->foundIds($small));
        self::assertSame($this->foundIds($small + [self::PROBE => true]), $this->foundIds($small));
        self::assertSame($this->foundIds($big + [self::PROBE => true]), $this->foundIds($big));
    }

    /** cf із кількома дочірніми категоріями дає рядок на кожну; GROUP BY схлопує їх. */
    public function testFeatureInTwoChildCategoriesNotDuplicated(): void
    {
        $filter = [
            'feature_id' => $this->featureIds(),
            'visible' => 1,
            'category_id' => $this->categoryTree(),
            'have_products_in_categories' => $this->categoryTree(),
        ];

        $found = $this->foundIds($filter);

        self::assertContains($this->ids['value_in_stock'], $found);
        self::assertSame(array_values(array_unique($found)), $found, 'значення повторюється');
        self::assertSame($this->foundIds($filter + [self::PROBE => true]), $found);
    }

    /**
     * Порядок тут збігається і без id у сортуванні: на цих даних оптимізатор
     * віддає нічиї в порядку id. Справжній гард — FeaturesValuesOrderTest.
     */
    public function testTiesOrderedById(): void
    {
        $found = $this->entity()->find(['feature_id' => $this->ids['feature_shared']]);

        $tieIds = [];
        foreach ($found as $value) {
            if (in_array((int) $value->id, [$this->ids['value_tie_a'], $this->ids['value_tie_b']], true)) {
                $tieIds[] = (int) $value->id;
            }
        }

        self::assertSame([$this->ids['value_tie_a'], $this->ids['value_tie_b']], $tieIds);
    }

    /** @return int[] */
    private function categoryTree(): array
    {
        return [$this->ids['category_parent'], $this->ids['category_child'], $this->ids['category_child2']];
    }

    /** @return int[] */
    private function featureIds(): array
    {
        return [$this->ids['feature_shared'], $this->ids['feature_own']];
    }

    /**
     * @param array<string, mixed> $filter
     * @return int[]
     */
    private function foundIds(array $filter): array
    {
        return array_map(static fn (object $value): int => (int) $value->id, $this->entity()->find($filter));
    }

    /** @param array<string, mixed> $filter */
    private function findSql(array $filter): string
    {
        ob_start();
        try {
            $this->entity()->debug()->find($filter);
        } finally {
            $sql = (string) ob_get_clean();
        }

        return $sql;
    }

    private function modulesFilters(): ModulesEntitiesFilters
    {
        return ServiceLocator::getInstance()->getService(ModulesEntitiesFilters::class);
    }

    private function entity(): FeaturesValuesEntity
    {
        return ServiceLocator::getInstance()->getService(EntityFactory::class)->get(FeaturesValuesEntity::class);
    }

    /**
     * Батьківська категорія з двома дочірніми, дві властивості (спільна для всіх
     * трьох і тільки батьківська) і товари для кожного випадку, який розрізняють
     * умови наявності, видимості й категорії.
     */
    private function buildFixture(): void
    {
        $parent = $this->insert('categories', ['parent_id' => 0, 'name' => 'fx parent', 'url' => 'fx-parent', 'visible' => 1]);
        $child = $this->insert('categories', ['parent_id' => $parent, 'name' => 'fx child', 'url' => 'fx-child', 'visible' => 1]);
        $child2 = $this->insert('categories', ['parent_id' => $parent, 'name' => 'fx child2', 'url' => 'fx-child2', 'visible' => 1]);
        $other = $this->insert('categories', ['parent_id' => 0, 'name' => 'fx other', 'url' => 'fx-other', 'visible' => 1]);
        $side = $this->insert('categories', ['parent_id' => 0, 'name' => 'fx side', 'url' => 'fx-side', 'visible' => 1]);
        $this->ids += [
            'category_parent' => $parent,
            'category_child' => $child,
            'category_child2' => $child2,
            'category_other' => $other,
            'category_side' => $side,
        ];

        $shared = $this->insert('features', ['name' => 'fx shared', 'url' => 'fx-shared', 'in_filter' => 1]);
        $own = $this->insert('features', ['name' => 'fx own', 'url' => 'fx-own', 'in_filter' => 1]);
        $this->ids += ['feature_shared' => $shared, 'feature_own' => $own];

        foreach ([$parent, $child, $child2] as $category) {
            $this->insert('categories_features', ['category_id' => $category, 'feature_id' => $shared]);
        }
        $this->insert('categories_features', ['category_id' => $parent, 'feature_id' => $own]);

        $this->ids += [
            'value_in_stock'   => $this->value($shared, 'alpha', 1),
            'value_tie_a'      => $this->value($shared, 'tie', 5),
            'value_tie_b'      => $this->value($shared, 'tie', 5),
            'value_no_stock'   => $this->value($shared, 'bravo', 2),
            'value_null_stock' => $this->value($own, 'charlie', 1),
            'value_hidden'     => $this->value($own, 'delta', 2),
            'value_other_cat'  => $this->value($own, 'echo', 3),
            'value_unused'     => $this->value($own, 'foxtrot', 4),
            'value_orphan'     => $this->value($own, 'golf', 5),
            'value_split_flags'    => $this->value($shared, 'hotel', 6),
            'value_split_category' => $this->value($shared, 'india', 7),
        ];

        $this->product('product_in_stock', $parent, true, 5, ['value_in_stock', 'value_tie_a']);
        $this->product('product_no_stock', $parent, true, 0, ['value_no_stock', 'value_tie_b']);
        $this->product('product_null_stock', $parent, true, null, ['value_null_stock']);
        $this->product('product_hidden', $parent, false, 5, ['value_hidden']);
        $this->product('product_other_cat', $other, true, 5, ['value_other_cat']);

        // Кожну умову виконує якийсь товар значення, але жоден не виконує всі.
        $this->product('product_split_no_stock', $parent, true, 0, ['value_split_flags']);
        $this->product('product_split_hidden', $parent, false, 5, ['value_split_flags']);
        $this->product('product_split_in_category', $parent, false, 0, ['value_split_category']);
        $this->product('product_split_elsewhere', $side, true, 5, ['value_split_category']);

        // Товару немає, а варіант і рядок зв'язку лишилися: так виглядає база після ручного видалення.
        $orphanProduct = (int) $this->pdo->fetchValue("SELECT COALESCE(MAX(id), 0) + 1000000 FROM {$this->px}products");
        $this->insert('variants', ['product_id' => $orphanProduct, 'stock' => 5, 'price' => 1]);
        $this->insert('products_features_values', ['product_id' => $orphanProduct, 'value_id' => $this->ids['value_orphan']]);
        $this->ids['product_orphan'] = $orphanProduct;

        // Батьківська категорія має бути не меншою за поріг режиму існування. Зв'язки на
        // неіснуючі товари не мають рядків pf, тож вибірки не змінюють, лише лічильник.
        $threshold = (new \ReflectionClassConstant(FeaturesValuesEntity::class, 'EXISTENCE_MIN_CATEGORY_PRODUCTS'))->getValue();
        $rows = [];
        for ($i = 1; $i <= $threshold; $i++) {
            $rows[] = sprintf('(%d, %d)', $orphanProduct + $i, $parent);
        }
        $this->pdo->exec("INSERT INTO {$this->px}products_categories (product_id, category_id) VALUES " . implode(', ', $rows));
    }

    private function value(int $featureId, string $value, int $position): int
    {
        $id = $this->insert('features_values', [
            'feature_id' => $featureId,
            'value'      => $value,
            'translit'   => $value,
            'position'   => $position,
        ]);

        foreach ($this->pdo->fetchCol("SELECT id FROM {$this->px}languages") as $langId) {
            $this->insert('lang_features_values', [
                'lang_id'          => $langId,
                'feature_value_id' => $id,
                'value'            => $value,
                'translit'         => $value,
                'position'         => $position,
            ]);
        }

        return $id;
    }

    /** @param string[] $valueKeys */
    private function product(string $key, int $categoryId, bool $visible, ?int $stock, array $valueKeys): void
    {
        $id = $this->insert('products', [
            'name'             => 'fx ' . $key,
            'url'              => 'fx-' . $key,
            'visible'          => (int) $visible,
            'main_category_id' => $categoryId,
        ]);
        $this->insert('variants', ['product_id' => $id, 'stock' => $stock, 'price' => 1]);
        $this->insert('products_categories', ['product_id' => $id, 'category_id' => $categoryId]);
        foreach ($valueKeys as $valueKey) {
            $this->insert('products_features_values', ['product_id' => $id, 'value_id' => $this->ids[$valueKey]]);
        }

        $this->ids[$key] = $id;
    }

    /** @param array<string, scalar|null> $row */
    private function insert(string $table, array $row): int
    {
        $columns = array_keys($row);
        $this->pdo->perform(
            sprintf(
                'INSERT INTO %s%s (%s) VALUES (%s)',
                $this->px,
                $table,
                implode(', ', array_map(static fn (string $c): string => '`' . $c . '`', $columns)),
                implode(', ', array_map(static fn (string $c): string => ':' . $c, $columns))
            ),
            $row
        );

        return (int) $this->pdo->lastInsertId();
    }
}

/** Порожній модульний фільтр: нічого не звужує, лише відправляє find() старим шляхом. */
class LegacyPathProbeFilter extends AbstractModuleEntityFilter
{
    public function apply($value, $filter): void
    {
    }
}
