<?php

namespace Security;

use PHPUnit\Framework\TestCase;

/**
 * Розширення модулів на [Контролер, 'fetch'] запускаються з BackendMainHelper::
 * beforeControllerProcedure() і часто виконують дії з POST: масове створення
 * накладних на сторінці замовлень тощо. Якщо викликати їх до перевірки прав,
 * менеджер без доступу до сторінки все одно запускає її дії.
 */
class ControllerExtendersAfterAccessTest extends TestCase
{
    public function testControllerExtendersRunOnlyForAnAllowedPage(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/backend/Controllers/IndexAdmin.php');

        $this->assertMatchesRegularExpression(
            '~\$allowed = \$this->mayOpenController\([^;]*\);.*?if \(\$allowed\) \{\s*\$backendMainHelper->beforeControllerProcedure\(static::class\);\s*\}~s',
            $source
        );
        $this->assertSame(1, substr_count($source, 'beforeControllerProcedure('), 'другий виклик оминув би перевірку');
        $this->assertStringContainsString('return $allowed;', $source);
    }
}
