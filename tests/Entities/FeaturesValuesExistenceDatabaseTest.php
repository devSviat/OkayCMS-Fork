<?php

namespace Entities;

use Aura\Sql\ExtendedPdo;
use Okay\Core\Config;
use Okay\Core\EntityFactory;
use Okay\Core\ServiceLocator;
use Okay\Entities\FeaturesValuesEntity;
use PHPUnit\Framework\TestCase;

/**
 * Вибірка значень властивостей на живій базі: порядок, наявність і видимість
 * рахуються SQL-ом, який мок не перевіряє. Тест іде проти MariaDB і
 * пропускається, де її немає (CI без бази).
 *
 * Фікстура створюється в транзакції й відкочується, тож база лишається як була.
 */
class FeaturesValuesExistenceDatabaseTest extends TestCase
{
    private ExtendedPdo $pdo;
    private string $px;

    /** @var array<string, int> */
    private array $ids = [];

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
        $this->buildFixture();
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

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

    private function entity(): FeaturesValuesEntity
    {
        return ServiceLocator::getInstance()->getService(EntityFactory::class)->get(FeaturesValuesEntity::class);
    }

    /**
     * Дві категорії (батьківська й дочірня), дві властивості (спільна для обох
     * і тільки батьківська), вісім значень і товари для кожного випадку, який
     * розрізняють умови наявності й видимості.
     */
    private function buildFixture(): void
    {
        $parent = $this->insert('categories', ['parent_id' => 0, 'name' => 'fx parent', 'url' => 'fx-parent', 'visible' => 1]);
        $child = $this->insert('categories', ['parent_id' => $parent, 'name' => 'fx child', 'url' => 'fx-child', 'visible' => 1]);
        $other = $this->insert('categories', ['parent_id' => 0, 'name' => 'fx other', 'url' => 'fx-other', 'visible' => 1]);
        $this->ids += ['category_parent' => $parent, 'category_child' => $child, 'category_other' => $other];

        $shared = $this->insert('features', ['name' => 'fx shared', 'url' => 'fx-shared', 'in_filter' => 1]);
        $own = $this->insert('features', ['name' => 'fx own', 'url' => 'fx-own', 'in_filter' => 1]);
        $this->ids += ['feature_shared' => $shared, 'feature_own' => $own];

        foreach ([$parent, $child] as $category) {
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
        ];

        $this->product('product_in_stock', $parent, true, 5, ['value_in_stock', 'value_tie_a']);
        $this->product('product_no_stock', $parent, true, 0, ['value_no_stock', 'value_tie_b']);
        $this->product('product_null_stock', $parent, true, null, ['value_null_stock']);
        $this->product('product_hidden', $parent, false, 5, ['value_hidden']);
        $this->product('product_other_cat', $other, true, 5, ['value_other_cat']);

        // Товару немає, а варіант і рядок зв'язку лишилися: так виглядає база після ручного видалення.
        $orphanProduct = (int) $this->pdo->fetchValue("SELECT COALESCE(MAX(id), 0) + 1000000 FROM {$this->px}products");
        $this->insert('variants', ['product_id' => $orphanProduct, 'stock' => 5, 'price' => 1]);
        $this->insert('products_features_values', ['product_id' => $orphanProduct, 'value_id' => $this->ids['value_unused']]);
        $this->ids['product_orphan'] = $orphanProduct;
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
