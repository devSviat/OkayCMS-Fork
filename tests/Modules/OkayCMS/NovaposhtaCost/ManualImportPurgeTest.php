<?php

namespace Modules\OkayCMS\NovaposhtaCost;

use PHPUnit\Framework\TestCase;

/**
 * Ручне оновлення довідників у налаштуваннях модуля (novaposhta_cost.tpl).
 *
 * finalImport видаляє все, що не оновилось після старту. Без totalCount
 * updateData повертає pagesNum 0 — пройдено лише першу сторінку, — і JS
 * усе одно кликав finalImport, видаляючи решту відділень типу. Cron у цьому
 * випадку кеш не чистить (NPCacheHelper::isUpdateComplete).
 *
 * Перевірка по вихідному коду: JS-оточення в наборі немає.
 */
class ManualImportPurgeTest extends TestCase
{
    public function testPurgeOnlyAfterKnownPageCount(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 4) . '/Okay/Modules/OkayCMS/NovaposhtaCost/Backend/design/html/novaposhta_cost.tpl'
        );
        $this->assertIsString($source);

        $start = strpos($source, 'function doUpdate(');
        $end = strpos($source, 'function finalUpdate(');
        $this->assertNotFalse($start);
        $this->assertNotFalse($end);
        $doUpdate = substr($source, $start, $end - $start);

        $this->assertMatchesRegularExpression(
            '~else if \(data\.hasOwnProperty\(\'pagesNum\'\) && data\.pagesNum > 0\) \{[^}]*finalUpdate\(~s',
            $doUpdate,
            'finalUpdate має кликатись лише коли кількість сторінок відома'
        );
        $this->assertSame(1, substr_count($doUpdate, 'finalUpdate('), 'інша гілка знову чистить кеш');
    }
}
