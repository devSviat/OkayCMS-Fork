<?php

namespace Security;

use Okay\Core\Security\BackendReturnUrl;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Шаблони адмінки друкують href="{$smarty.get.return}" без екранування:
 * посилання з ?return="><script>… після «Зберегти» виконувало скрипт
 * у сесії менеджера.
 */
class BackendReturnUrlTest extends TestCase
{
    private const ROOT = 'https://shop.example';

    #[DataProvider('rejected')]
    public function testForeignOrScriptUrlIsDropped(mixed $value): void
    {
        self::assertNull(BackendReturnUrl::sanitize($value, self::ROOT));
    }

    public static function rejected(): array
    {
        return [
            'javascript' => ['javascript:alert(1)'],
            'javascript з пробілом' => [' javascript:alert(1)'],
            'javascript у змішаному регістрі' => ['JaVaScRiPt:alert(1)'],
            'javascript з табуляцією' => ["java\tscript:alert(1)"],
            'javascript закодований' => ['javascript%3Aalert(1)'],
            'vbscript' => ['vbscript:msgbox(1)'],
            'data' => ['data:text/html,<script>alert(1)</script>'],
            'вихід з атрибута' => ['"><script>alert(1)</script>'],
            'чужий хост' => ['https://evil.example/backend/'],
            'протокол-відносний' => ['//evil.example/'],
            'протокол-відносний закодований' => ['%2F%2Fevil.example/'],
            'протокол-відносний закодований двічі' => ['%252F%252Fevil.example/'],
            'шлях, що декодується в //' => ['/%2Fevil.example/'],
            'зворотні скісні' => ['\\\\evil.example/'],
            'скісна і зворотна' => ['/\\evil.example/'],
            'зворотна закодована' => ['/%5Cevil.example/'],
            'облікові дані перед чужим хостом' => ['https://shop.example@evil.example/'],
            'піддомен-пастка' => ['https://shop.example.evil.example/'],
            'інша схема на свій хост' => ['ftp://shop.example/'],
            'перенос рядка' => ["/backend/\r\nSet-Cookie: a=b"],
            'перенос рядка закодований' => ['/backend/%0d%0aSet-Cookie:%20a=b'],
            'нульовий байт' => ['/backend/%00'],
            'відносний без скісної' => ['index.php?controller=OrdersAdmin'],
            'масив' => [['/backend/']],
            'порожньо' => [''],
        ];
    }

    #[DataProvider('ownListUrls')]
    public function testOwnUrlsStay(string $own): void
    {
        self::assertSame($own, BackendReturnUrl::sanitize($own, self::ROOT));
    }

    /** Так їх будує {url ... return=$smarty.server.REQUEST_URI} на сторінках списків. */
    public static function ownListUrls(): array
    {
        return [
            'категорія' => ['/backend/index.php?controller=CategoriesAdmin&category_id=4'],
            'фільтри й сторінка' => ['/backend/index.php?controller=ProductsAdmin&category_id=3&brand_id=2&filter=visible&page=4'],
            'пошук кирилицею' => ['/backend/index.php?controller=ProductsAdmin&keyword=%D1%88%D0%B8%D0%BD%D0%B0&page=2'],
            'масив у query' => ['/backend/index.php?controller=OrdersAdmin&status=1&labels%5B%5D=2&labels%5B%5D=5'],
            'модульний контролер' => ['/backend/index.php?controller=OkayCMS.Feeds.FeedsAdmin&sort=name_desc'],
        ];
    }

    public function testOwnAbsoluteUrlStays(): void
    {
        self::assertSame(
            'https://shop.example/backend/index.php?controller=OrdersAdmin',
            BackendReturnUrl::sanitize('https://shop.example/backend/index.php?controller=OrdersAdmin', self::ROOT)
        );
    }

    /** Шлях свій, але в ньому розмітка: лишається посиланням, з атрибута не виходить. */
    public function testOwnPathCannotBreakTheAttribute(): void
    {
        $clean = BackendReturnUrl::sanitize('/backend/?k="><b onmouseover=\'x\'>`', self::ROOT);

        self::assertSame('/backend/?k=%22%3E%3Cb onmouseover=%27x%27%3E%60', $clean);
    }

    /** Без входу через backend/index.php санітайзер не діє на жоден шаблон. */
    public function testBackendEntrySanitizesBeforeControllersRun(): void
    {
        $entry = file_get_contents(dirname(__DIR__, 2) . '/backend/index.php');

        $sanitize = strpos($entry, 'BackendReturnUrl::sanitize($_GET[\'return\']');
        self::assertNotFalse($sanitize);
        self::assertLessThan(strpos($entry, '$managerMenu = $DI->get'), $sanitize);
    }
}
