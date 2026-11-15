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
    private const BLOCKERS = ['features', 'product_id', 'brand_id', 'other_filter', 'price', 'product_keyword', 'brand'];

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
        return array_combine(self::BLOCKERS, array_map(static fn (string $b): array => [$b], self::BLOCKERS));
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
    }

    public function testVisibleStillLegacyInThisStep(): void
    {
        self::assertLegacy(self::findSql(['feature_id' => [1], 'in_stock' => true, 'visible' => 1])['sql']);
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
    private static function findSql(array $filter, array $moduleFilterNames = []): array
    {
        $class = new ReflectionClass(FeaturesValuesEntity::class);
        $entity = $class->newInstanceWithoutConstructor();
        $parent = $class->getParentClass();

        $queryFactory = new ExistenceQueryFactory();
        $db = new ExistenceCapturingDatabase();

        $parent->getProperty('queryFactory')->setValue($entity, $queryFactory);
        $parent->getProperty('select')->setValue($entity, $queryFactory->newSelect());
        $parent->getProperty('db')->setValue($entity, $db);
        $parent->getProperty('lang')->setValue($entity, new ExistenceLanguages());
        $parent->getProperty('entity')->setValue($entity, new ExistenceEntityFactory($queryFactory));
        $parent->getProperty('modulesFilters')->setValue($entity, new ExistenceModulesFilters($moduleFilterNames));

        $entity->find($filter);

        return ['sql' => preg_replace('/\s+/', ' ', str_replace('`', '', $db->sql)), 'binds' => $db->binds];
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

    /** @var array<string, mixed> */
    public array $binds = [];

    public function query(QueryInterface $query, $debug = false): bool
    {
        $this->sql = $query->getStatement();
        $this->binds = $query->getBindValues();

        return true;
    }

    public function results($field = null, $mapped = null): array
    {
        return [];
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
