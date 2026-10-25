<?php

namespace Helpers;

use Okay\Helpers\CanonicalHelper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * filterChpuUrl() віддає `?keyword=…` без шляху, а контролери ставили перед ним
 * слеш: canonical пошуку вів на `/all-products/?keyword=…`, що 301-редиректить назад.
 */
class CanonicalAppendFilterUrlTest extends TestCase
{
    #[DataProvider('cases')]
    public function testJoin(string $base, string $filter, string $expected): void
    {
        $helper = (new ReflectionClass(CanonicalHelper::class))->newInstanceWithoutConstructor();

        self::assertSame($expected, $helper->appendFilterUrl($base, $filter));
    }

    public static function cases(): array
    {
        return [
            'без фільтра' => ['https://shop.example/catalog/x', '', 'https://shop.example/catalog/x'],
            'фільтр' => ['https://shop.example/catalog/x', '/brand-a', 'https://shop.example/catalog/x/brand-a'],
            'лише пошук' => ['https://shop.example/all-products', '?keyword=a', 'https://shop.example/all-products?keyword=a'],
            'фільтр і пошук' => ['https://shop.example/catalog/x', '/brand-a?keyword=b', 'https://shop.example/catalog/x/brand-a?keyword=b'],
        ];
    }
}
