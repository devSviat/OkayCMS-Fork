<?php

namespace Entities;

use Aura\Sql\ExtendedPdo;
use Okay\Core\Config;
use Okay\Core\EntityFactory;
use Okay\Core\ServiceLocator;
use Okay\Entities\ProductsEntity;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * ProductsEntity::countGroupedByBrand() рахує посадкові «бренд + значення» одним запитом. Вони
 * відкриваються для індексу за цими числами, тож кожне мусить дорівнювати count() сторінки з тим
 * самим фільтром: інакше в індекс і карту потрапить сторінка з іншою кількістю товарів.
 * Тест пропускається без бази; фікстура живе в транзакції й відкочується.
 */
class ProductsBrandFeatureCountsDatabaseTest extends TestCase
{
    private const array TRANSLIT = ['value_one' => 'fxbfcone', 'value_two' => 'fxbfctwo', 'value_other' => 'fxbfcother'];

    private ExtendedPdo $pdo;
    private string $px;
    private bool $inTransaction = false;

    /** @var array<string, int> */
    private array $ids = [];

    /** @var array<string, int[]> піддерева категорій фікстури */
    private array $children = [];

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
    }

    protected function tearDown(): void
    {
        if ($this->inTransaction && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    public static function stock(): array
    {
        return ['усі товари' => [false], 'лише в наявності' => [true]];
    }

    /** Для кожного піддерева, бренду й значення — те саме, що count() сторінки фільтра. */
    #[DataProvider('stock')]
    public function testCountsMatchTheListingCount(bool $inStock): void
    {
        foreach (['parent', 'leaf_a', 'leaf_b', 'leaf_c'] as $category) {
            $filter = $this->filter($this->children[$category], $inStock);

            $values = [];
            foreach ($this->entity()->countGroupedByBrand($filter, [$this->ids['feature']]) as $row) {
                $this->assertSame($this->ids['feature'], (int) $row->feature_id);
                $values[(int) $row->brand_id][(int) $row->value_id] = (int) $row->count;
            }
            $pairs = [];
            foreach ($this->entity()->countGroupedByBrand($filter) as $row) {
                $pairs[(int) $row->brand_id] = (int) $row->count;
            }

            foreach (['brand_a', 'brand_b', 'brand_hidden'] as $brand) {
                $brandId = $this->ids[$brand];
                $this->assertSame(
                    $brand === 'brand_hidden' ? 0 : $this->listingCount($filter + ['brand_id' => [$brandId]]),
                    $pairs[$brandId] ?? 0,
                    "$category / $brand"
                );
                foreach (['value_one', 'value_two', 'value_other'] as $value) {
                    $expected = $brand === 'brand_hidden' || $value === 'value_other' ? 0 : $this->listingCount($filter + [
                        'brand_id' => [$brandId],
                        'features' => [$this->ids['feature'] => [self::TRANSLIT[$value]]],
                    ]);
                    $this->assertSame($expected, $values[$brandId][$this->ids[$value]] ?? 0, "$category / $brand / $value");
                }
            }
        }
    }

    /** Групування за категорією дає для листа те саме, що окремий запит на цей лист. */
    public function testPerCategoryMatchesSingleLeaf(): void
    {
        $leaves = ['leaf_a', 'leaf_b', 'leaf_c'];
        $ids = array_map(fn ($k) => $this->ids[$k], $leaves);

        $grouped = $this->rows($this->entity()->countGroupedByBrand($this->filter($ids, false), [$this->ids['feature']], true), true);
        $groupedPairs = $this->rows($this->entity()->countGroupedByBrand($this->filter($ids, false), [], true), true);

        foreach ($leaves as $leaf) {
            $id = $this->ids[$leaf];
            $this->assertSame(
                $this->rows($this->entity()->countGroupedByBrand($this->filter([$id], false), [$this->ids['feature']])),
                $grouped[$id] ?? [],
                $leaf
            );
            $this->assertSame(
                $this->rows($this->entity()->countGroupedByBrand($this->filter([$id], false))),
                $groupedPairs[$id] ?? [],
                $leaf
            );
        }
    }

    /** Товар у двох листах батька — один товар батьківської сторінки, а не два. */
    public function testProductInTwoLeavesCountsOnceForTheParent(): void
    {
        $rows = $this->rows($this->entity()->countGroupedByBrand($this->filter($this->children['parent'], false), [$this->ids['feature']]));

        $this->assertSame(1, $rows[$this->ids['brand_a'] . '/' . $this->ids['value_two']]);
    }

    public function testPerCategoryWithoutCategoryFilterIsEmpty(): void
    {
        $this->assertSame([], $this->entity()->countGroupedByBrand(['visible' => 1], [], true));
    }

    private function filter(array $categoriesIds, bool $inStock): array
    {
        $filter = ['category_id' => $categoriesIds, 'visible' => 1];
        if ($inStock) {
            $filter['in_stock'] = true;
        }

        return $filter;
    }

    private function listingCount(array $filter): int
    {
        return (int) $this->entity()->count($filter);
    }

    /**
     * @param object[] $rows
     * @return array<string, int>|array<int, array<string, int>>
     */
    private function rows(array $rows, bool $perCategory = false): array
    {
        $result = [];
        foreach ($rows as $row) {
            $key = $row->brand_id . (isset($row->value_id) ? '/' . $row->value_id : '');
            if ($perCategory) {
                $result[(int) $row->category_id][$key] = (int) $row->count;
            } else {
                $result[$key] = (int) $row->count;
            }
        }
        array_walk($result, static function (&$v) { if (is_array($v)) { ksort($v); } });
        ksort($result);

        return $result;
    }

    private function entity(): ProductsEntity
    {
        return ServiceLocator::getInstance()->getService(EntityFactory::class)->get(ProductsEntity::class);
    }

    /**
     * parent → leaf_a, leaf_b; leaf_c окремо. Властивість feature: one, two; other — інша властивість.
     */
    private function buildFixture(): void
    {
        $this->ids['parent'] = $this->insert('categories', ['parent_id' => 0, 'name' => 'fx parent', 'url' => 'fx-bfc-parent', 'visible' => 1]);
        $this->ids['leaf_a'] = $this->insert('categories', ['parent_id' => $this->ids['parent'], 'name' => 'fx a', 'url' => 'fx-bfc-a', 'visible' => 1]);
        $this->ids['leaf_b'] = $this->insert('categories', ['parent_id' => $this->ids['parent'], 'name' => 'fx b', 'url' => 'fx-bfc-b', 'visible' => 1]);
        $this->ids['leaf_c'] = $this->insert('categories', ['parent_id' => 0, 'name' => 'fx c', 'url' => 'fx-bfc-c', 'visible' => 1]);
        $this->children = [
            'parent' => [$this->ids['parent'], $this->ids['leaf_a'], $this->ids['leaf_b']],
            'leaf_a' => [$this->ids['leaf_a']],
            'leaf_b' => [$this->ids['leaf_b']],
            'leaf_c' => [$this->ids['leaf_c']],
        ];

        $this->ids['brand_a'] = $this->brand('a', 1);
        $this->ids['brand_b'] = $this->brand('b', 1);
        $this->ids['brand_hidden'] = $this->brand('hidden', 0);

        $this->ids['feature'] = $this->insert('features', ['name' => 'fx feature', 'url' => 'fxbfcfeature']);
        $this->ids['feature_other'] = $this->insert('features', ['name' => 'fx other', 'url' => 'fxbfcother']);
        $this->ids['value_one'] = $this->value($this->ids['feature'], self::TRANSLIT['value_one']);
        $this->ids['value_two'] = $this->value($this->ids['feature'], self::TRANSLIT['value_two']);
        $this->ids['value_other'] = $this->value($this->ids['feature_other'], self::TRANSLIT['value_other']);

        $this->product('a1', 'brand_a', ['leaf_a'], true, null, ['value_one']);
        $this->product('a2', 'brand_a', ['leaf_a'], true, 3, ['value_one', 'value_other']);
        $this->product('a3', 'brand_a', ['leaf_a', 'leaf_b'], true, 1, ['value_two']);
        $this->product('a4', 'brand_a', ['leaf_a'], true, 0, ['value_one']);
        $this->product('a5', 'brand_a', ['leaf_a'], false, 5, ['value_one']);
        $this->product('a6', 'brand_a', ['leaf_c'], true, 5, []);
        $this->product('a7', 'brand_a', ['leaf_c'], true, 5, ['value_one', 'value_two']);
        $this->product('b1', 'brand_b', ['leaf_a'], true, 5, ['value_one']);
        $this->product('b2', 'brand_b', ['parent'], true, 0, ['value_two']);
        $this->product('h1', 'brand_hidden', ['leaf_a'], true, 5, ['value_one']);
    }

    private function brand(string $key, int $visible): int
    {
        return $this->insert('brands', [
            'name' => 'fx brand ' . $key,
            'url' => 'fx-bfc-brand-' . $key,
            'meta_title' => '',
            'meta_keywords' => '',
            'meta_description' => '',
            'position' => 0,
            'visible' => $visible,
        ]);
    }

    private function value(int $featureId, string $value): int
    {
        $id = $this->insert('features_values', ['feature_id' => $featureId, 'value' => $value, 'translit' => $value, 'position' => 0]);
        foreach ($this->pdo->fetchCol("SELECT id FROM {$this->px}languages") as $langId) {
            $this->insert('lang_features_values', [
                'lang_id' => $langId,
                'feature_value_id' => $id,
                'value' => $value,
                'translit' => $value,
                'position' => 0,
            ]);
        }

        return $id;
    }

    /**
     * @param string[] $categoryKeys
     * @param string[] $valueKeys
     */
    private function product(string $key, string $brandKey, array $categoryKeys, bool $visible, ?int $stock, array $valueKeys): void
    {
        $id = $this->insert('products', [
            'name' => 'fx ' . $key,
            'url' => 'fx-bfc-' . $key,
            'brand_id' => $this->ids[$brandKey],
            'visible' => (int) $visible,
            'main_category_id' => $this->ids[$categoryKeys[0]],
        ]);
        $this->insert('variants', ['product_id' => $id, 'stock' => $stock, 'price' => 1]);
        foreach ($categoryKeys as $categoryKey) {
            $this->insert('products_categories', ['product_id' => $id, 'category_id' => $this->ids[$categoryKey]]);
        }
        foreach ($valueKeys as $valueKey) {
            $this->insert('products_features_values', ['product_id' => $id, 'value_id' => $this->ids[$valueKey]]);
        }
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
