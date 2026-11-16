<?php

namespace Core;

use Aura\Sql\ExtendedPdo;
use Aura\SqlQuery\QueryFactory as AuraQueryFactory;
use Okay\Core\Database;
use Okay\Core\QueryFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Aura Insert::addRows() добудовує останній рядок пакета лише під час збирання тексту запиту.
 * Якщо прив'язки взяти раніше за текст, вставка рівно двох рядків губить значення другого
 * («Parameter 'id_1' is missing from the bound values»).
 */
class BulkInsertBindingTest extends TestCase
{
    /** @return array<string, array{int}> */
    public static function rowCounts(): array
    {
        return ['один рядок' => [1], 'два рядки' => [2], 'три рядки' => [3]];
    }

    #[DataProvider('rowCounts')]
    public function testEveryPlaceholderIsBound(int $count): void
    {
        $performed = null;
        $pdo = $this->createStub(ExtendedPdo::class);
        $pdo->method('perform')->willReturnCallback(function (string $statement, array $values) use (&$performed) {
            $performed = [$statement, $values];
            return $this->createStub(\PDOStatement::class);
        });
        $db = new Database($pdo, new NullLogger(), ['prefix' => 'ok_'], new QueryFactory(new AuraQueryFactory('mysql')));

        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $rows[] = ['id' => 10 + $i, 'name' => "n{$i}"];
        }
        $insert = new AuraQueryFactory('mysql')->newInsert()->into('__t')->addRows($rows);

        $this->assertTrue($db->query($insert));
        [$statement, $values] = $performed;
        preg_match_all('/:([a-z_][a-z0-9_]*)/i', $statement, $placeholders);
        $this->assertNotEmpty($placeholders[1]);
        foreach ($placeholders[1] as $name) {
            $this->assertArrayHasKey($name, $values, "{$count} рядки(ів): :{$name} без значення");
        }
    }
}
