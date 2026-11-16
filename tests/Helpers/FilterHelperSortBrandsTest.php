<?php

namespace Helpers;

use Okay\Core\EntityFactory;
use Okay\Helpers\FilterHelper;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class FilterHelperSortBrandsTest extends TestCase
{
    public function testOneQueryForManyLinks(): void
    {
        $entity = $this->entity([
            ['id' => 1, 'url' => 'brend-a', 'position' => 1],
            ['id' => 2, 'url' => 'brend-b', 'position' => 2],
            ['id' => 3, 'url' => 'brend-c', 'position' => 3],
        ]);
        $helper = $this->makeHelper($entity);

        for ($i = 0; $i < 50; $i++) {
            $pair = $i % 2 ? ['brend-a', 'brend-b'] : ['brend-c', 'brend-a'];
            $this->sortBrands($helper, $pair);
        }

        $this->assertSame(1, $entity->findCalls);
    }

    public function testOrdersByPositionThenId(): void
    {
        $helper = $this->makeHelper($this->entity([
            ['id' => 1, 'url' => 'brend-a', 'position' => 3],
            ['id' => 9, 'url' => 'brend-b', 'position' => 1],
            ['id' => 4, 'url' => 'brend-c', 'position' => 1],
        ]));

        $this->assertSame(
            ['brend-c', 'brend-b', 'brend-a'],
            $this->sortBrands($helper, ['brend-a', 'brend-b', 'brend-c'])
        );
    }

    public function testCaseInsensitiveLookupReturnsDatabaseUrl(): void
    {
        $helper = $this->makeHelper($this->entity([
            ['id' => 1, 'url' => 'brend-a', 'position' => 1],
            ['id' => 2, 'url' => 'brend-b', 'position' => 2],
        ]));

        $this->assertSame(['brend-a', 'brend-b'], $this->sortBrands($helper, ['Brend-B', 'brend-a']));
    }

    public function testUnknownAndDuplicateUrlsDropped(): void
    {
        $helper = $this->makeHelper($this->entity([
            ['id' => 1, 'url' => 'brend-a', 'position' => 1],
            ['id' => 2, 'url' => 'brend-b', 'position' => 2],
        ]));

        $this->assertSame(
            ['brend-a', 'brend-b'],
            $this->sortBrands($helper, ['brend-a', 'nema', 'brend-a', 'brend-b'])
        );
    }

    public function testSingleBrandUntouchedWithoutQuery(): void
    {
        $entity = $this->entity([['id' => 1, 'url' => 'brend-a', 'position' => 1]]);
        $helper = $this->makeHelper($entity);

        $this->assertSame(['Brend-A'], $this->sortBrands($helper, ['Brend-A']));
        $this->assertSame(0, $entity->findCalls);
    }

    public function testAllUnknownReturnsFalse(): void
    {
        $helper = $this->makeHelper($this->entity([['id' => 1, 'url' => 'brend-a', 'position' => 1]]));

        $this->assertFalse($this->sortBrands($helper, ['nema', 'nema-two']));
    }

    private function sortBrands(FilterHelper $helper, array $urls)
    {
        $method = (new ReflectionClass(FilterHelper::class))->getMethod('sortBrands');

        return $method->invoke($helper, $urls);
    }

    /** Сутність тут не потрібна: перевіряється кількість запитів і порядок, а не побудова SQL. */
    private function entity(array $rows): object
    {
        return new class($rows) {
            public int $findCalls = 0;

            public function __construct(private array $rows)
            {
            }

            public function __call(string $name, array $arguments): static
            {
                return $this;
            }

            public function find(array $filter = []): array
            {
                $this->findCalls++;

                return array_map(static fn(array $row) => (object)$row, $this->rows);
            }
        };
    }

    private function makeHelper(object $entity): FilterHelper
    {
        $factory = $this->createStub(EntityFactory::class);
        $factory->method('get')->willReturn($entity);

        $reflection = new ReflectionClass(FilterHelper::class);
        $helper = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('entityFactory')->setValue($helper, $factory);

        return $helper;
    }
}
