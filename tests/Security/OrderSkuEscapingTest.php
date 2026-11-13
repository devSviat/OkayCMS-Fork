<?php

namespace Security;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Артикул покупки копіюється з варіанта, а той приходить і з імпорту файлів
 * постачальника. Адмінка автоекранування не має, тож без |escape розмітка з
 * артикула виконувалась у картці й у списку замовлень.
 */
class OrderSkuEscapingTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function templates(): iterable
    {
        yield 'order card' => ['backend/design/html/order.tpl'];
        yield 'orders list' => ['backend/design/html/orders.tpl'];
    }

    #[DataProvider('templates')]
    public function testSkuIsEscapedWhereverItIsPrinted(string $path): void
    {
        $template = (string) file_get_contents(dirname(__DIR__, 2) . '/' . $path);

        preg_match_all('~\{\$purchase->sku(?:\|[^}]*)?\}~', $template, $printed);
        $this->assertNotEmpty($printed[0]);
        foreach ($printed[0] as $tag) {
            $this->assertStringContainsString('|escape', $tag, $path);
        }
    }
}
