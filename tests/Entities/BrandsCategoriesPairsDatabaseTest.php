<?php

namespace Entities;

use Aura\Sql\ExtendedPdo;
use Okay\Core\Config;
use Okay\Core\EntityFactory;
use Okay\Core\ServiceLocator;
use Okay\Entities\BrandsEntity;
use PHPUnit\Framework\TestCase;

/**
 * BrandsEntity::findCategoriesBrandsIds() на живій базі: позначка «фільтр за брендом дасть 404»
 * збігається з CategoryController, лише поки пари рахуються тими самими join-ами й умовами,
 * що й find(). Тест пропускається без бази; фікстура живе в транзакції й відкочується.
 */
class BrandsCategoriesPairsDatabaseTest extends TestCase
{
    private ExtendedPdo $pdo;
    private string $px;
    private bool $inTransaction = false;

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
        $this->inTransaction = true;
        $this->buildFixture();
    }

    protected function tearDown(): void
    {
        if ($this->inTransaction && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    private function filter(array $categoriesIds): array
    {
        return ['category_id' => $categoriesIds, 'visible' => 1, 'product_visible' => 1];
    }

    public function testPairsHonourVisibilityAndCategories(): void
    {
        $pairs = $this->entity()->findCategoriesBrandsIds($this->filter([$this->ids['cat_a'], $this->ids['cat_b']]));
        $pairs = array_map(static function (array $ids) { sort($ids); return $ids; }, $pairs);
        ksort($pairs);

        $expected = [
            $this->ids['cat_a'] => [$this->ids['brand_one'], $this->ids['brand_two']],
            $this->ids['cat_b'] => [$this->ids['brand_one']],
        ];
        ksort($expected);

        // brand_one двічі в cat_a (два товари) — пара одна; прихований товар brand_two і
        // прихований бренд у cat_b не рахуються; cat_c поза фільтром.
        $this->assertSame($expected, $pairs);
    }

    /** На кожну категорію пари дають той самий набір брендів, що й find() з тим самим фільтром. */
    public function testPairsMatchFindPerCategory(): void
    {
        $categories = [$this->ids['cat_a'], $this->ids['cat_b'], $this->ids['cat_c']];
        $pairs = $this->entity()->findCategoriesBrandsIds($this->filter($categories));

        foreach ($categories as $categoryId) {
            $found = array_map('intval', array_keys($this->entity()->mappedBy('id')->find($this->filter([$categoryId]))));
            $fromPairs = $pairs[$categoryId] ?? [];
            sort($found);
            sort($fromPairs);
            $this->assertSame($found, $fromPairs, "категорія $categoryId");
        }
    }

    public function testNoCategoryFilterNoQuery(): void
    {
        $this->assertSame([], $this->entity()->findCategoriesBrandsIds(['visible' => 1, 'product_visible' => 1]));
    }

    private function entity(): BrandsEntity
    {
        return ServiceLocator::getInstance()->getService(EntityFactory::class)->get(BrandsEntity::class);
    }

    private function buildFixture(): void
    {
        foreach (['cat_a', 'cat_b', 'cat_c'] as $key) {
            $this->ids[$key] = $this->insert('categories', ['parent_id' => 0, 'name' => 'fx ' . $key, 'url' => 'fx-' . $key, 'visible' => 1]);
        }

        $this->ids['brand_one'] = $this->brand('one', 1);
        $this->ids['brand_two'] = $this->brand('two', 1);
        $this->ids['brand_hidden'] = $this->brand('hidden', 0);

        $this->product('a1', 'brand_one', 'cat_a', true);
        $this->product('a2', 'brand_one', 'cat_a', true);
        $this->product('a3', 'brand_two', 'cat_a', true);
        $this->product('b1', 'brand_one', 'cat_b', true);
        $this->product('b2', 'brand_two', 'cat_b', false);
        $this->product('b3', 'brand_hidden', 'cat_b', true);
        $this->product('c1', 'brand_two', 'cat_c', true);
    }

    private function brand(string $key, int $visible): int
    {
        return $this->insert('brands', [
            'name' => 'fx brand ' . $key,
            'url' => 'fx-brand-' . $key,
            'meta_title' => '',
            'meta_keywords' => '',
            'meta_description' => '',
            'position' => 0,
            'visible' => $visible,
        ]);
    }

    private function product(string $key, string $brandKey, string $categoryKey, bool $visible): void
    {
        $id = $this->insert('products', [
            'name' => 'fx ' . $key,
            'url' => 'fx-pairs-' . $key,
            'brand_id' => $this->ids[$brandKey],
            'visible' => (int) $visible,
            'main_category_id' => $this->ids[$categoryKey],
        ]);
        $this->insert('products_categories', ['product_id' => $id, 'category_id' => $this->ids[$categoryKey]]);
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
