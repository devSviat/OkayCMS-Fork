<?php

namespace Entities;

use Aura\SqlQuery\QueryFactory as AuraQueryFactory;
use Okay\Entities\OrdersEntity;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Назва в покупці зберігається мовою, якою оформлено замовлення, і не завжди
 * містить актуальний артикул. Тому пошук за партномером дивиться ще й у sku покупки.
 */
class OrdersKeywordSkuTest extends TestCase
{
    public function testKeywordMatchesPurchaseSku(): void
    {
        [$sql, $bind] = self::keywordSql('SS-123 abc');

        self::assertStringContainsString('OR sku LIKE :keyword_sku_0', $sql);
        self::assertStringContainsString('OR sku LIKE :keyword_sku_1', $sql);
        self::assertSame('%SS-123%', $bind['keyword_sku_0']);
        self::assertSame('%abc%', $bind['keyword_sku_1']);
    }

    /** @return array{0: string, 1: array<string, mixed>} */
    private static function keywordSql(string $keyword): array
    {
        $select = (new AuraQueryFactory('mysql'))->newSelect();
        $select->from('__orders AS o')->cols(['o.id']);

        $entity = new ReflectionClass(OrdersEntity::class);
        $orders = $entity->newInstanceWithoutConstructor();
        $entity->getProperty('select')->setValue($orders, $select);
        $entity->getMethod('filter__keyword')->invoke($orders, $keyword);

        return [(string)$select, $select->getBindValues()];
    }
}
