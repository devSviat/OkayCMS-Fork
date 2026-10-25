<?php

namespace Core\Routes;

use Okay\Core\Routes\Strategies\AllBlog\DefaultStrategy;
use Okay\Core\Settings;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Слаг роуту blog — голий {$url}, а префікс живе лише в patterns. Без defaults
 * роутер передавав контролеру порожній url, і hreflang /all-posts вів на головну.
 */
class AllBlogRouteDefaultsTest extends TestCase
{
    public function testPrefixIsAlsoTheDefaultUrl(): void
    {
        [$slug, $patterns, $defaults] = self::strategy('statti')->generateRouteParams('');

        self::assertSame('{$url}', $slug);
        self::assertSame(['{$url}' => 'statti'], $patterns);
        self::assertSame(['{$url}' => 'statti'], $defaults);
    }

    public function testEmptySettingFallsBackToAllPosts(): void
    {
        [, , $defaults] = self::strategy('')->generateRouteParams('');

        self::assertSame(['{$url}' => 'all-posts'], $defaults);
    }

    private static function strategy(string $prefix): DefaultStrategy
    {
        $reflector = new ReflectionClass(DefaultStrategy::class);
        $strategy = $reflector->newInstanceWithoutConstructor();
        $settingsReflector = new ReflectionClass(Settings::class);
        $settings = $settingsReflector->newInstanceWithoutConstructor();
        $settingsReflector->getProperty('vars')->setValue($settings, ['all_blog_routes_template__default' => $prefix]);
        $reflector->getProperty('settings')->setValue($strategy, $settings);

        return $strategy;
    }
}
