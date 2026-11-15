<?php

namespace Helpers;

use Okay\Core\EntityFactory;
use Okay\Entities\PaymentsEntity;
use Okay\Helpers\OrdersHelper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;

/**
 * Сторінка замовлення доступна будь-кому, хто знає її адресу, тож усе, що вона приймає
 * POST-ом і віддає в шаблон, лежить на межі довіри.
 */
class OrdersHelperPaymentMethodTest extends TestCase
{
    private const int DELIVERY_ID = 3;
    private const int OFFERED = 10;
    private const int DISABLED = 11;
    private const int OTHER_DELIVERY = 12;

    private const string SECRET = 'fake-private-key-0000';

    /**
     * Спосіб оплати → [enabled, доставки, з якими він повʼязаний].
     * Фейк застосовує лише ті фільтри, які передав помічник: пропущений фільтр
     * робить відповідний тест червоним, а не випадково зеленим.
     */
    private const array METHODS = [
        self::OFFERED        => [1, [self::DELIVERY_ID]],
        self::DISABLED       => [0, [self::DELIVERY_ID]],
        self::OTHER_DELIVERY => [1, [99]],
    ];

    public function testOfferedMethodOnUnpaidOrderIsAccepted(): void
    {
        $this->assertTrue($this->helper()->canChangePaymentMethod($this->order(), self::OFFERED));
    }

    public function testPaidOrderKeepsItsMethod(): void
    {
        $paid = $this->order(paid: 1);

        $this->assertFalse($this->helper()->canChangePaymentMethod($paid, self::OFFERED));
        $this->assertTrue($this->helper()->canChangePaymentMethod($this->order(), self::OFFERED), 'control: same method, unpaid');
    }

    public function testPaidOrderCannotResetItsMethod(): void
    {
        $this->assertFalse($this->helper()->canChangePaymentMethod($this->order(paid: 1), null));
        $this->assertTrue($this->helper()->canChangePaymentMethod($this->order(), null), 'control: reset on unpaid order');
    }

    public function testDisabledMethodIsRejected(): void
    {
        $this->assertFalse($this->helper()->canChangePaymentMethod($this->order(), self::DISABLED));
    }

    public function testMethodNotOfferedForTheDeliveryIsRejected(): void
    {
        $this->assertFalse($this->helper()->canChangePaymentMethod($this->order(), self::OTHER_DELIVERY));
        $this->assertTrue(
            $this->helper()->canChangePaymentMethod($this->order(deliveryId: 99), self::OTHER_DELIVERY),
            'control: the same method on its own delivery'
        );
    }

    public function testOrderWithoutDeliveryAcceptsNoMethod(): void
    {
        $this->assertFalse($this->helper()->canChangePaymentMethod($this->order(deliveryId: null), self::OFFERED));
    }

    #[DataProvider('nonPositiveIds')]
    public function testNonPositiveIdIsRejected(int $id): void
    {
        $this->assertFalse($this->helper()->canChangePaymentMethod($this->order(), $id));
    }

    public static function nonPositiveIds(): array
    {
        return ['zero' => [0], 'negative' => [-self::OFFERED]];
    }

    public function testUnknownMethodIsRejected(): void
    {
        $this->assertFalse($this->helper()->canChangePaymentMethod($this->order(), 999));
    }

    public function testOrderPaymentMethodReachesTheStorefrontWithoutSettings(): void
    {
        $method = $this->helper()->getOrderPaymentMethod($this->order(paymentMethodId: self::OFFERED));

        $this->assertSame(self::OFFERED, $method->id);
        $this->assertSame('Vendor/Pay', $method->module);
        $this->assertObjectNotHasProperty('settings', $method);
        $this->assertStringNotContainsString(self::SECRET, serialize($method));
    }

    public function testOrderPaymentMethodsListReachesTheStorefrontWithoutSettings(): void
    {
        $methods = $this->helper()->getOrderPaymentMethodsList($this->order());

        $this->assertNotEmpty($methods);
        foreach ($methods as $method) {
            $this->assertObjectNotHasProperty('settings', $method);
        }
        $this->assertStringNotContainsString(self::SECRET, serialize($methods));
    }

    private function order(int $paid = 0, ?int $deliveryId = self::DELIVERY_ID, ?int $paymentMethodId = null): object
    {
        return (object)[
            'id'                => 5,
            'paid'              => (string)$paid,
            'delivery_id'       => $deliveryId,
            'payment_method_id' => $paymentMethodId,
        ];
    }

    private static function row(int $id): object
    {
        return (object)[
            'id'       => $id,
            'module'   => 'Vendor/Pay',
            'name'     => 'Pay',
            'enabled'  => (string)self::METHODS[$id][0],
            'settings' => serialize(['vendor__private_key' => self::SECRET, 'vendor__currency' => 'UAH']),
        ];
    }

    private function helper(): OrdersHelper
    {
        $paymentsEntity = $this->createStub(PaymentsEntity::class);
        $paymentsEntity->method('get')->willReturnCallback(
            fn ($id) => isset(self::METHODS[(int)$id]) ? self::row((int)$id) : false
        );
        $paymentsEntity->method('find')->willReturnCallback(fn (array $filter) => self::matching($filter));
        $paymentsEntity->method('findOne')->willReturnCallback(
            fn (array $filter) => self::matching($filter)[0] ?? false
        );

        $entityFactory = $this->createStub(EntityFactory::class);
        $entityFactory->method('get')->willReturnCallback(fn ($class) => match ($class) {
            PaymentsEntity::class => $paymentsEntity,
        });

        $helper = (new ReflectionClass(OrdersHelper::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(OrdersHelper::class, 'entityFactory'))->setValue($helper, $entityFactory);

        return $helper;
    }

    /** @return list<object> */
    private static function matching(array $filter): array
    {
        $rows = [];
        foreach (self::METHODS as $id => [$enabled, $deliveries]) {
            if (isset($filter['id']) && !in_array($id, (array)$filter['id'])) {
                continue;
            }
            if (isset($filter['enabled']) && (int)$filter['enabled'] !== $enabled) {
                continue;
            }
            if (array_key_exists('delivery_id', $filter) && !in_array((int)$filter['delivery_id'], $deliveries, true)) {
                continue;
            }
            $rows[] = self::row($id);
        }

        return $rows;
    }
}
