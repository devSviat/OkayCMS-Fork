<?php

namespace Security;

use Okay\Core\EntityFactory;
use Okay\Core\Notify;
use Okay\Core\ServiceLocator;
use Okay\Core\Design;
use Okay\Core\StorefrontPaymentMethods;
use Okay\Entities\DeliveriesEntity;
use Okay\Entities\OrdersEntity;
use Okay\Entities\PaymentsEntity;
use Okay\Helpers\MainHelper;
use Okay\Helpers\NotifyHelper;
use Okay\Helpers\OrdersHelper;
use Okay\Helpers\PaymentsHelper;
use Okay\Helpers\UserHelper;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;
use RuntimeException;

/**
 * Налаштування способу оплати містять ключі платіжних сервісів. Кожне місце, що віддає
 * способи оплати у шаблон вітрини чи листа, має віддавати їх без `settings`.
 */
class StorefrontPaymentMethodsTest extends TestCase
{
    private const string SECRET = 'fake-private-key-0000';

    public function testStripRemovesSettingsFromOneObjectAndFromAList(): void
    {
        $one = self::row(1);
        $list = [1 => self::row(1), 2 => self::row(2)];

        $this->assertSame($one, StorefrontPaymentMethods::withoutSettings($one));
        $this->assertSame($list, StorefrontPaymentMethods::withoutSettings($list));

        $this->assertObjectNotHasProperty('settings', $one);
        $this->assertStringNotContainsString(self::SECRET, serialize([$one, $list]));
        $this->assertSame('Vendor/Pay', $one->module);
    }

    public function testStripPassesEmptyResultsThrough(): void
    {
        $this->assertFalse(StorefrontPaymentMethods::withoutSettings(false));
        $this->assertNull(StorefrontPaymentMethods::withoutSettings(null));
        $this->assertSame([], StorefrontPaymentMethods::withoutSettings([]));
    }

    public function testFooterPaymentMethodsHaveNoSettings(): void
    {
        $entityFactory = $this->entityFactory($this->paymentsEntity());
        $serviceLocator = $this->createStub(ServiceLocator::class);
        $serviceLocator->method('getService')->willReturn($entityFactory);

        $helper = $this->bare(MainHelper::class, ['SL' => $serviceLocator]);

        $this->assertNoSecrets($helper->getPaymentMethods());
    }

    public function testCartPaymentMethodsHaveNoSettings(): void
    {
        $helper = $this->bare(PaymentsHelper::class, ['entityFactory' => $this->entityFactory($this->paymentsEntity())]);

        $this->assertNoSecrets($helper->getCartPaymentsList(null));
    }

    public function testAccountPaymentMethodsHaveNoSettings(): void
    {
        $helper = $this->bare(UserHelper::class, ['entityFactory' => $this->entityFactory($this->paymentsEntity())]);

        $this->assertNoSecrets($helper->getPaymentMethodsListForUser());
    }

    public function testCustomerOrderEmailGetsPaymentMethodWithoutSettings(): void
    {
        $this->assertNoSecrets([$this->paymentMethodAssignedBy('emailOrderUser')]);
    }

    public function testAdminOrderEmailGetsPaymentMethodWithoutSettings(): void
    {
        $this->assertNoSecrets([$this->paymentMethodAssignedBy('emailOrderAdmin')]);
    }

    private function assertNoSecrets(mixed $methods): void
    {
        $this->assertNotEmpty($methods);
        foreach ($methods as $method) {
            $this->assertIsObject($method);
            $this->assertObjectNotHasProperty('settings', $method);
        }
        $this->assertStringNotContainsString(self::SECRET, serialize($methods));
    }

    /**
     * Лист рендериться далеко за присвоєнням способу оплати, тож тест зупиняє його
     * винятком саме на цьому присвоєнні й забирає присвоєне значення.
     */
    private function paymentMethodAssignedBy(string $method): object
    {
        $assigned = null;
        $design = $this->createStub(Design::class);
        $design->method('assign')->willReturnCallback(function ($name, $value = null) use (&$assigned) {
            if ($name === 'payment_method') {
                $assigned = $value;
                throw new RuntimeException('payment_method assigned');
            }
            return null;
        });

        $orders = $this->createStub(OrdersEntity::class);
        $orders->method('get')->willReturn((object)['id' => 5, 'email' => 'buyer@example.com', 'lang_id' => null, 'delivery_id' => 1, 'payment_method_id' => 1]);

        $deliveries = $this->createStub(DeliveriesEntity::class);
        $deliveries->method('findOne')->willReturn((object)['id' => 1]);

        $notifyHelper = $this->createStub(NotifyHelper::class);
        $notifyHelper->method('needSendEmailOrderUser')->willReturn(true);
        $notifyHelper->method('needSendEmailOrderAdmin')->willReturn(true);

        $ordersHelper = $this->createStub(OrdersHelper::class);
        $ordersHelper->method('getOrderPurchasesList')->willReturn([]);
        $ordersHelper->method('getDiscounts')->willReturn([]);

        $paymentsEntity = $this->paymentsEntity();
        $entityFactory = $this->createStub(EntityFactory::class);
        $entityFactory->method('get')->willReturnCallback(fn ($class) => match ($class) {
            OrdersEntity::class     => $orders,
            DeliveriesEntity::class => $deliveries,
            PaymentsEntity::class   => $paymentsEntity,
            default                 => $this->createStub($class),
        });

        $notify = $this->bare(Notify::class, [
            'entityFactory' => $entityFactory,
            'design'        => $design,
            'notifyHelper'  => $notifyHelper,
            'ordersHelper'  => $ordersHelper,
        ]);

        try {
            $notify->$method(5);
            $this->fail("$method() never assigned payment_method");
        } catch (RuntimeException) {
        }

        $this->assertIsObject($assigned);

        return $assigned;
    }

    private function paymentsEntity(): PaymentsEntity
    {
        $paymentsEntity = $this->createStub(PaymentsEntity::class);
        $paymentsEntity->method('find')->willReturnCallback(fn () => [1 => self::row(1), 2 => self::row(2)]);
        $paymentsEntity->method('findOne')->willReturnCallback(fn () => self::row(1));

        return $paymentsEntity;
    }

    private function entityFactory(PaymentsEntity $paymentsEntity): EntityFactory
    {
        $entityFactory = $this->createStub(EntityFactory::class);
        $entityFactory->method('get')->willReturn($paymentsEntity);

        return $entityFactory;
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @param array<string, mixed> $properties
     * @return T
     */
    private function bare(string $class, array $properties): object
    {
        $object = (new ReflectionClass($class))->newInstanceWithoutConstructor();
        foreach ($properties as $name => $value) {
            (new ReflectionProperty($class, $name))->setValue($object, $value);
        }

        return $object;
    }

    private static function row(int $id): object
    {
        return (object)[
            'id'       => $id,
            'module'   => 'Vendor/Pay',
            'name'     => 'Pay',
            'enabled'  => '1',
            'settings' => serialize(['vendor__private_key' => self::SECRET]),
        ];
    }
}
