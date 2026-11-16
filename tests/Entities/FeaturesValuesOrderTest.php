<?php

namespace Entities;

use Aura\SqlQuery\QueryFactory as AuraQueryFactory;
use Okay\Core\Languages;
use Okay\Entities\FeaturesValuesEntity;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Рівні (position, value) без третього ключа віддаються у порядку, який обирає
 * оптимізатор, тож зміна плану переставляє значення на сторінці без жодної помилки.
 */
class FeaturesValuesOrderTest extends TestCase
{
    public function testDefaultOrderEndsWithIdTiebreak(): void
    {
        self::assertSame(
            'ORDER BY l.position ASC, l.value ASC, fv.id ASC',
            self::orderByClause(self::languages('l'))
        );
    }

    public function testWithoutLanguageJoinUsesPlainAlias(): void
    {
        self::assertSame(
            'ORDER BY fv.position ASC, fv.value ASC, fv.id ASC',
            self::orderByClause(self::languages('fv'))
        );
    }

    private static function orderByClause(Languages $languages): string
    {
        $class = new ReflectionClass(FeaturesValuesEntity::class);
        $entity = $class->newInstanceWithoutConstructor();

        $select = (new AuraQueryFactory('mysql'))->newSelect();
        $select->from('__features_values AS fv')->cols(['fv.id']);

        $parent = $class->getParentClass();
        $parent->getProperty('select')->setValue($entity, $select);
        $parent->getProperty('lang')->setValue($entity, $languages);

        $entity->order();

        $sql = (string) $select;

        return preg_replace('/\s+/', ' ', str_replace('`', '', trim(substr($sql, strpos($sql, 'ORDER BY')))));
    }

    private static function languages(string $alias): Languages
    {
        return new class ($alias) extends Languages {
            public function __construct(private string $alias)
            {
            }

            public function getLangAlias($tableAlias, $params = [])
            {
                return $this->alias;
            }
        };
    }
}
