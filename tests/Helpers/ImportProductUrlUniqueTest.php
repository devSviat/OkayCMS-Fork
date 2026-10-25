<?php

namespace Helpers;

use Okay\Admin\Helpers\BackendImportHelper;
use Okay\Core\EntityFactory;
use Okay\Entities\ProductsEntity;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Імпорт брав url з CSV як є: два товари з одним url роутер віддає як один,
 * і другий стає недоступним (так у broken загубились 4524 і 437).
 */
class ImportProductUrlUniqueTest extends TestCase
{
    /** @var array<string, object> url => товар */
    private array $byUrl = [];

    public function testFreeUrlIsKept(): void
    {
        self::assertSame('free-url', $this->resolve('free-url', null));
    }

    public function testOwnUrlIsKept(): void
    {
        $this->product(7, 'own-url');

        self::assertSame('own-url', $this->resolve('own-url', 7));
    }

    public function testNewProductGetsSuffix(): void
    {
        $this->product(1, 'taken');
        $this->product(2, 'taken-2');

        self::assertSame('taken-3', $this->resolve('taken', null));
    }

    public function testExistingProductKeepsItsUrl(): void
    {
        $this->product(1, 'taken');
        $this->product(5, 'mine');

        self::assertSame('mine', $this->resolve('taken', 5));
    }

    private function product(int $id, string $url): void
    {
        $this->byUrl[$url] = (object)['id' => $id, 'url' => $url];
    }

    private function resolve(string $url, ?int $productId): string
    {
        $products = $this->createStub(ProductsEntity::class);
        $products->method('get')->willReturnCallback(function ($key) {
            if (is_int($key)) {
                foreach ($this->byUrl as $product) {
                    if ($product->id === $key) {
                        return $product;
                    }
                }
                return null;
            }
            return $this->byUrl[$key] ?? null;
        });
        $factory = $this->createStub(EntityFactory::class);
        $factory->method('get')->willReturn($products);

        $reflector = new ReflectionClass(BackendImportHelper::class);
        $helper = $reflector->newInstanceWithoutConstructor();
        $reflector->getProperty('entityFactory')->setValue($helper, $factory);

        return $reflector->getMethod('resolveImportProductUrl')->invoke($helper, $url, $productId);
    }
}
