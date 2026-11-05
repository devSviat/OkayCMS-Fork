<?php

namespace Helpers;

use Okay\Core\Modules\Extender\ChainExtender;
use Okay\Core\Modules\Extender\ExtensionInterface;
use Okay\Helpers\DeliveriesHelper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionClass;

/**
 * Ціну доставки замовлення можуть змінювати модулі (ланцюг
 * DeliveriesHelper::prepareDeliveryPriceInfo). Ядро не пропускає в замовлення
 * від'ємну чи нечислову ціну від жодного з них — замість неї ціна способу
 * доставки з адмінки. Це страховка на випадок помилки в модулі.
 */
class DeliveryPriceGuardTest extends TestCase
{
    protected function tearDown(): void
    {
        (new ReflectionClass(ChainExtender::class))->getProperty('triggers')->setValue(null, []);
    }

    /** @return iterable<string, array{mixed}> */
    public static function badPrices(): iterable
    {
        yield 'від\'ємна' => [-1500];
        yield 'від\'ємний рядок' => ['-1500'];
        yield 'не число' => ['abc'];
        yield 'порожній рядок' => [''];
        yield 'null' => [null];
        yield 'нескінченність' => [INF];
        yield 'NaN' => [NAN];
        yield 'масив' => [['x']];
    }

    #[DataProvider('badPrices')]
    public function testBadPriceFromModuleFallsBackToDeliverySettings(mixed $price): void
    {
        $this->moduleSetsPrice($price);

        $info = $this->helper()->prepareDeliveryPriceInfo($this->delivery(), (object)['id' => 1, 'total_price' => 1799]);

        $this->assertSame(100.0, (float)$info['delivery_price']);
    }

    /** @return iterable<string, array{mixed, float}> */
    public static function goodPrices(): iterable
    {
        yield 'розрахунок модуля' => [90, 90.0];
        yield 'рядок з розрахунку' => ['95.50', 95.5];
        yield 'безкоштовно за акцією' => [0, 0.0];
    }

    #[DataProvider('goodPrices')]
    public function testValidPriceFromModuleIsKept(mixed $price, float $expected): void
    {
        $this->moduleSetsPrice($price);

        $info = $this->helper()->prepareDeliveryPriceInfo($this->delivery(), (object)['id' => 1, 'total_price' => 1799]);

        $this->assertSame($expected, (float)$info['delivery_price']);
    }

    public function testWithoutModulesDeliverySettingsAreUsed(): void
    {
        $info = $this->helper()->prepareDeliveryPriceInfo($this->delivery(), (object)['id' => 1, 'total_price' => 1799]);

        $this->assertSame(100.0, (float)$info['delivery_price']);
    }

    private function moduleSetsPrice(mixed $price): void
    {
        $extension = new class implements ExtensionInterface {
            public static mixed $price = null;

            public function setPrice($result, $delivery, $order)
            {
                $result['delivery_price'] = self::$price;

                return $result;
            }
        };
        $class = get_class($extension);
        $class::$price = $price;

        (new ChainExtender())->newExtension(DeliveriesHelper::class, 'prepareDeliveryPriceInfo', $class, 'setPrice');
    }

    private function helper(): DeliveriesHelper
    {
        $reflection = new ReflectionClass(DeliveriesHelper::class);
        $helper = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('logger')->setValue($helper, new NullLogger());

        return $helper;
    }

    private function delivery(): object
    {
        return (object)['id' => 3, 'price' => 100, 'free_from' => 999999, 'separate_payment' => 0, 'paid' => 1];
    }
}
