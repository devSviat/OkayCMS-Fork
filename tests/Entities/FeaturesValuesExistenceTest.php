<?php

namespace Entities;

use Aura\SqlQuery\Common\SelectInterface;
use Aura\SqlQuery\QueryFactory as AuraQueryFactory;
use Aura\SqlQuery\QueryInterface;
use Okay\Core\Languages;
use Okay\Core\Modules\AbstractModuleEntityFilter;
use Okay\Core\Modules\ModulesEntitiesFilters;
use Okay\Core\QueryFactory\Select;
use Okay\Entities\FeaturesValuesEntity;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionClassConstant;

/**
 * Форма запиту find() у режимі існування й межі цього режиму. Помилка тут тиха:
 * зайвий джойн лише сповільнює сторінку, а забутий ковтається в лог, тож ловить
 * її тільки перевірка згенерованого SQL.
 *
 * Вибірки будуються голою Aura в обгортці без конструктора: справжній
 * конструктор Select тягне Database через ServiceLocator, а в CI бази немає.
 */
class FeaturesValuesExistenceTest extends TestCase
{
    public function testInStockAloneUsesOneCorrelatedSubquery(): void
    {
        $sql = self::findSql(['feature_id' => [1, 2], 'in_stock' => true])['sql'];

        self::assertDoesNotMatchRegularExpression('/JOIN __products_features_values AS pf\b/', $sql);
        self::assertDoesNotMatchRegularExpression('/JOIN __products AS p\b/', $sql);
        self::assertStringContainsString('pfe.value_id = fv.id', $sql);
        self::assertStringContainsString('pe.id = pfe.product_id', $sql);
        self::assertStringContainsString('LIMIT 1) = 1', $sql);
        self::assertSame(1, substr_count($sql, '__products_features_values AS pfe'), 'один підзапит на значення');
    }

    public function testCategoryKeepsFeatureJoinsInExistenceMode(): void
    {
        $sql = self::findSql(['feature_id' => [1], 'category_id' => [3], 'in_stock' => true])['sql'];

        self::assertStringContainsString('pfe.value_id = fv.id', $sql);
        self::assertMatchesRegularExpression('/JOIN __features AS f\b/', $sql);
        self::assertMatchesRegularExpression('/JOIN __categories_features AS cf\b/', $sql);
        self::assertDoesNotMatchRegularExpression('/JOIN __products AS p\b/', $sql);
    }

    /** @return array<string, array{string}> */
    public static function blockerProvider(): array
    {
        $blockers = (new ReflectionClassConstant(FeaturesValuesEntity::class, 'EXISTENCE_BLOCKERS'))->getValue();

        return array_combine($blockers, array_map(static fn (string $b): array => [$b], $blockers));
    }

    #[DataProvider('blockerProvider')]
    public function testBlockerKeepsLegacyJoins(string $blocker): void
    {
        $values = [
            'features'        => [1 => ['a']],
            'product_id'      => [5],
            'brand_id'        => [6],
            'other_filter'    => ['featured'],
            'price'           => ['min' => 1, 'max' => 2],
            'product_keyword' => 'x',
            'brand'           => 'x',
        ];

        self::assertArrayHasKey($blocker, $values, "для блокера {$blocker} немає значення в тесті");
        $sql = self::findSql(['feature_id' => [1], $blocker => $values[$blocker], 'in_stock' => true])['sql'];

        self::assertLegacy($sql);
    }

    public function testModuleFilterKeepsLegacyJoins(): void
    {
        self::assertLegacy(self::findSql(['feature_id' => [1], 'in_stock' => true, 'vendor_stock' => 1], ['vendor_stock'])['sql']);
    }

    /** Ядро не знає SQL фільтра, яким модуль підмінив умову, тож підзапит не може його відтворити. */
    public function testModuleFilterNamedLikeConditionKeepsLegacyPath(): void
    {
        self::assertLegacy(self::findSql(['feature_id' => [1], 'in_stock' => true], ['in_stock'])['sql']);
        self::assertLegacy(self::findSql(['feature_id' => [1], 'in_stock' => true, 'visible' => 1], ['visible'])['sql']);
        self::assertLegacy(self::findSql(
            ['feature_id' => [1], 'visible' => 1, 'have_products_in_categories' => [3]],
            ['have_products_in_categories']
        )['sql']);
    }

    public function testFacetFilterUsesOneSubquery(): void
    {
        $sql = self::findSql([
            'visible' => 1,
            'feature_id' => [1, 2],
            'category_id' => [3, 4],
            'have_products_in_categories' => [3, 4],
        ])['sql'];

        self::assertSame(1, substr_count($sql, '__products_features_values AS pfe'), 'один підзапит на значення');
        self::assertMatchesRegularExpression('/pe\.visible = :exv_visible/', $sql);
        self::assertMatchesRegularExpression('/pce\.category_id IN \(/', $sql);
        self::assertDoesNotMatchRegularExpression('/JOIN __products_features_values AS pf\b/', $sql);
        self::assertDoesNotMatchRegularExpression('/JOIN __products AS p\b/', $sql);
        self::assertDoesNotMatchRegularExpression('/JOIN __products_categories AS pc\b/', $sql);
        self::assertMatchesRegularExpression('/JOIN __categories_features AS cf\b/', $sql);
        self::assertStringContainsString('GROUP BY fv.id', $sql);
    }

    /** Старий шлях без visible/in_stock не джойнив p, тож і підзапит не йде через pe. */
    public function testCategoriesOnlyDoesNotJoinProducts(): void
    {
        $sql = self::findSql(['feature_id' => [1], 'have_products_in_categories' => [3]])['sql'];

        self::assertMatchesRegularExpression('/JOIN __products_categories AS pce\b/', $sql);
        self::assertDoesNotMatchRegularExpression('/__products AS pe\b/', $sql);
        self::assertDoesNotMatchRegularExpression('/JOIN __products_features_values AS pf\b/', $sql);
    }

    /** @return array<string, array{int, bool}> */
    public static function categorySizeProvider(): array
    {
        $threshold = (new ReflectionClassConstant(FeaturesValuesEntity::class, 'EXISTENCE_MIN_CATEGORY_PRODUCTS'))->getValue();

        return [
            'на одиницю менше порогу' => [$threshold - 1, false],
            'рівно поріг' => [$threshold, true],
        ];
    }

    #[DataProvider('categorySizeProvider')]
    public function testSmallCategoryFacetKeepsLegacyPath(int $categoryProducts, bool $existence): void
    {
        $captured = self::findSql([
            'visible' => 1,
            'feature_id' => [1, 2],
            'category_id' => [3, 4],
            'have_products_in_categories' => [3, 4],
        ], [], $categoryProducts);

        self::assertMatchesRegularExpression('/COUNT\(\*\) AS count FROM __products_categories WHERE category_id IN \(/', $captured['queries'][0]);
        if ($existence) {
            self::assertStringContainsString('pfe.value_id = fv.id', $captured['sql']);
        } else {
            self::assertLegacy($captured['sql']);
            self::assertMatchesRegularExpression('/JOIN __products_categories AS pc\b/', $captured['sql']);
        }
    }

    /** Без категорії рахувати нічого: /all-products і база з hide ідуть у режим існування без запиту. */
    public function testNoCategoryCountWithoutCategories(): void
    {
        $captured = self::findSql(['feature_id' => [1], 'visible' => 1, 'in_stock' => true], [], 0);

        self::assertCount(1, $captured['queries']);
        self::assertStringContainsString('pfe.value_id = fv.id', $captured['sql']);
    }

    public function testSelectedFeaturesKeepLegacyPath(): void
    {
        $sql = self::findSql([
            'visible' => 1,
            'feature_id' => [1, 2],
            'category_id' => [3],
            'have_products_in_categories' => [3],
            'features' => [1 => ['a']],
        ])['sql'];

        self::assertLegacy($sql);
        self::assertMatchesRegularExpression('/JOIN __products_categories AS pc\b/', $sql);
    }

    public function testWithoutConditionsNothingChanges(): void
    {
        $sql = self::findSql(['feature_id' => [1], 'category_id' => [3]])['sql'];

        self::assertStringNotContainsString('pfe', $sql);
        self::assertDoesNotMatchRegularExpression('/JOIN __products_features_values AS pf\b/', $sql);
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function boundFilterProvider(): array
    {
        return [
            'база з hide'  => [['feature_id' => [876543], 'category_id' => [987654], 'in_stock' => true]],
            'фасет з hide' => [[
                'feature_id' => [876543],
                'category_id' => [987654],
                'have_products_in_categories' => [765432],
                'visible' => 1,
                'in_stock' => true,
            ]],
        ];
    }

    #[DataProvider('boundFilterProvider')]
    public function testValuesAreBoundNotInlined(array $filter): void
    {
        $query = self::findSql($filter);

        foreach (['876543', '987654', '765432'] as $id) {
            self::assertStringNotContainsString($id, $query['sql'], "id {$id} склеєно в SQL");
        }

        $bound = json_encode($query['binds']);
        foreach (['876543', '987654'] as $id) {
            self::assertStringContainsString($id, $bound);
        }
        if (isset($filter['visible'])) {
            self::assertMatchesRegularExpression('/visible\s*=\s*:\w+/', $query['sql']);
            self::assertStringContainsString('765432', $bound);
        }
    }

    /**
     * Масиви Aura.Sql розгортає через str_replace, тож :category_id зіпсував би
     * :category_ids, а однакове ім'я двох умов лишає лише останнє значення.
     */
    #[DataProvider('boundFilterProvider')]
    public function testBindNamesHaveNoSharedPrefix(array $filter): void
    {
        preg_match_all('/:(\w+)/', self::findSql($filter)['sql'], $matches);
        $names = $matches[1];

        self::assertNotEmpty($names);
        self::assertSame(array_unique($names), $names, 'одне bind-ім\'я в кількох умовах: ' . implode(', ', $names));

        foreach ($names as $name) {
            foreach ($names as $other) {
                self::assertFalse(
                    $name !== $other && str_starts_with($other, $name),
                    ":{$name} — префікс :{$other}"
                );
            }
        }
    }

    private static function assertLegacy(string $sql): void
    {
        self::assertMatchesRegularExpression('/JOIN __products_features_values AS pf\b/', $sql);
        self::assertMatchesRegularExpression('/JOIN __products AS p\b/', $sql);
        self::assertStringNotContainsString('pfe', $sql);
    }

    /**
     * @param array<string, mixed> $filter
     * @param string[] $moduleFilterNames
     * @return array{sql: string, binds: array<string, mixed>}
     */
    private static function findSql(array $filter, array $moduleFilterNames = [], int $categoryProducts = 100000): array
    {
        $class = new ReflectionClass(FeaturesValuesEntity::class);
        $entity = $class->newInstanceWithoutConstructor();
        $parent = $class->getParentClass();

        $queryFactory = new ExistenceQueryFactory();
        $db = new ExistenceCapturingDatabase($categoryProducts);

        $parent->getProperty('queryFactory')->setValue($entity, $queryFactory);
        $parent->getProperty('select')->setValue($entity, $queryFactory->newSelect());
        $parent->getProperty('db')->setValue($entity, $db);
        $parent->getProperty('lang')->setValue($entity, new ExistenceLanguages());
        $parent->getProperty('entity')->setValue($entity, new ExistenceEntityFactory($queryFactory));
        $parent->getProperty('modulesFilters')->setValue($entity, new ExistenceModulesFilters($moduleFilterNames));

        $entity->find($filter);

        $normalize = static fn (string $sql): string => preg_replace('/\s+/', ' ', str_replace('`', '', $sql));

        return ['sql' => $normalize($db->sql), 'binds' => $db->binds, 'queries' => array_map($normalize, $db->queries)];
    }
}

class ExistenceSelect extends Select
{
    public function __construct(SelectInterface $queryObject)
    {
        $this->queryObject = $queryObject;
    }
}

class ExistenceQueryFactory
{
    private AuraQueryFactory $aura;

    public function __construct()
    {
        $this->aura = new AuraQueryFactory('mysql');
    }

    public function newSelect(): Select
    {
        return new ExistenceSelect($this->aura->newSelect());
    }
}

class ExistenceCapturingDatabase
{
    public string $sql = '';

    /** @var string[] */
    public array $queries = [];

    public function __construct(private int $categoryProducts)
    {
    }

    /** @var array<string, mixed> */
    public array $binds = [];

    public function query(QueryInterface $query, $debug = false): bool
    {
        $this->sql = $query->getStatement();
        $this->binds = $query->getBindValues();
        $this->queries[] = $this->sql;

        return true;
    }

    public function results($field = null, $mapped = null): array
    {
        return [];
    }

    public function result($field = null)
    {
        return $field === 'count' ? $this->categoryProducts : null;
    }
}

class ExistenceLanguages extends Languages
{
    public function __construct()
    {
    }

    public function getLangId()
    {
        return 1;
    }

    public function getLangAlias($tableAlias, $params = [])
    {
        return 'l';
    }

    public function getQuery($tableAlias, $langTable, $langObject, $params = [])
    {
        return ['join' => $langTable . ' AS l', 'cond' => "l.{$langObject}_id = {$tableAlias}.id AND l.lang_id = 1"];
    }
}

class ExistenceModulesFilters extends ModulesEntitiesFilters
{
    /** @param string[] $names */
    public function __construct(private array $names)
    {
    }

    public function hasFilter($entityClassName, $filterName)
    {
        return in_array($filterName, $this->names, true);
    }

    public function getFilterClassName($entityClassName, $filterName)
    {
        return ExistenceNoopModuleFilter::class;
    }

    public function getFilterMethod($entityClassName, $filterName)
    {
        return 'apply';
    }
}

class ExistenceNoopModuleFilter extends AbstractModuleEntityFilter
{
    public function apply($value, $filter): void
    {
    }
}

class ExistenceEntityFactory
{
    public function __construct(private ExistenceQueryFactory $queryFactory)
    {
    }

    public function get($class): object
    {
        return new class ($this->queryFactory) {
            public function __construct(private ExistenceQueryFactory $queryFactory)
            {
            }

            public function getSelect(array $filter = []): Select
            {
                $select = $this->queryFactory->newSelect();
                $select->from('__products AS p')->cols(['p.id']);

                return $select;
            }
        };
    }
}
