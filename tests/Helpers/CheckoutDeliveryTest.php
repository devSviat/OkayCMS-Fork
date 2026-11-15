<?php

namespace Helpers;

use Okay\Core\EntityFactory;
use Okay\Core\FrontTranslations;
use Okay\Core\Request;
use Okay\Core\Settings;
use Okay\Core\Validator;
use Okay\Entities\PaymentsEntity;
use Okay\Helpers\OrdersHelper;
use Okay\Helpers\ValidateHelper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;

/**
 * Оформлення з кошика бере delivery_id із POST. Доставка має бути з того списку, який кошик
 * пропонує покупцеві (DeliveriesHelper::getCartDeliveriesList()), а спосіб оплати звіряється
 * лише з такою доставкою.
 */
class CheckoutDeliveryTest extends TestCase
{
    private const int OFFERED = 3;
    private const int DISABLED = 2;
    private const int HIDDEN_BY_MODULE = 6;

    private const int METHOD = 18;

    private const string DELIVERY_ERROR = 'Оберіть доставку зі списку';
    private const string PAYMENT_ERROR = 'Оберіть спосіб оплати зі списку';

    /** Спосіб METHOD увімкнений і повʼязаний з усіма трьома доставками. */
    private const array LINKS = [self::OFFERED, self::DISABLED, self::HIDDEN_BY_MODULE];

    public function testOfferedDeliveryPasses(): void
    {
        $this->assertNull($this->validator()->getCartValidateError($this->order(self::OFFERED), $this->cartDeliveries()));
    }

    /** Обхід: вимкнена доставка, повʼязана з увімкненим способом, відкривала б цей спосіб. */
    public function testDisabledDeliveryLinkedToAnEnabledMethodIsRefused(): void
    {
        $order = $this->order(self::DISABLED);

        $this->assertTrue($this->ordersHelper()->isPaymentMethodOffered($order, self::METHOD), 'control: the method itself is linked and enabled');
        $this->assertSame(self::DELIVERY_ERROR, $this->validator()->getCartValidateError($order, $this->cartDeliveries()));
    }

    /** Модуль, що прибрав доставку зі списку кошика (наявність, сума), прибирає її і з оформлення. */
    public function testDeliveryTheCartDoesNotOfferIsRefused(): void
    {
        $order = $this->order(self::HIDDEN_BY_MODULE);

        $this->assertSame(self::DELIVERY_ERROR, $this->validator()->getCartValidateError($order, $this->cartDeliveries()));
        $this->assertNull(
            $this->validator()->getCartValidateError($order, $this->cartDeliveries(self::HIDDEN_BY_MODULE)),
            'control: the same delivery once the cart offers it'
        );
    }

    #[DataProvider('foreignIds')]
    public function testUnknownOrNegativeDeliveryIsRefused(int $id): void
    {
        $this->assertSame(self::DELIVERY_ERROR, $this->validator()->getCartValidateError($this->order($id), $this->cartDeliveries()));
    }

    public static function foreignIds(): array
    {
        return ['unknown' => [999], 'negative' => [-self::OFFERED]];
    }

    /** Без списку доставок рішення не ухвалити, тож перевірка закрита, а не пропущена. */
    public function testWithoutTheCartListAnyDeliveryIsRefused(): void
    {
        $this->assertSame(self::DELIVERY_ERROR, $this->validator()->getCartValidateError($this->order(self::OFFERED)));
    }

    /** Ядро не вимагало доставки: без неї замовлення проходить і способу оплати не отримує. */
    public function testMissingDeliveryPassesAsBefore(): void
    {
        $order = $this->order(0);
        $order->payment_method_id = 0;

        $this->assertNull($this->validator()->getCartValidateError($order, $this->cartDeliveries()));
    }

    /** Спосіб оплати звіряється вже з перевіреною доставкою: помилка доставки йде першою. */
    public function testPaymentIsNeverCheckedAgainstARefusedDelivery(): void
    {
        $orders = $this->createMock(OrdersHelper::class);
        $orders->expects($this->never())->method('isPaymentMethodOffered');

        $this->assertSame(self::DELIVERY_ERROR, $this->validator($orders)->getCartValidateError($this->order(self::DISABLED), $this->cartDeliveries()));
    }

    public function testPaymentIsCheckedAgainstTheAcceptedDelivery(): void
    {
        $order = $this->order(self::OFFERED);
        $orders = $this->createMock(OrdersHelper::class);
        $orders->expects($this->once())->method('isPaymentMethodOffered')->with($order, self::METHOD)->willReturn(false);

        $this->assertSame(self::PAYMENT_ERROR, $this->validator($orders)->getCartValidateError($order, $this->cartDeliveries()));
    }

    /** Ключі списку модулі можуть переставити; рішення бере id самої доставки. */
    public function testListIsMatchedByDeliveryIdNotByKey(): void
    {
        $this->assertNull($this->validator()->getCartValidateError($this->order(self::OFFERED), array_values($this->cartDeliveries())));
    }

    public function testErrorSurvivesAThemeWithoutTheTranslation(): void
    {
        $error = $this->validator(translations: false)->getCartValidateError($this->order(self::DISABLED), $this->cartDeliveries());

        $this->assertNotNull($error);
        $this->assertNotSame('', $error);
    }

    /** Помилку контактів розширення можуть зняти, тож вона не може затулити перевірку доставки. */
    public function testDeliveryErrorComesBeforeContactErrors(): void
    {
        $order = $this->order(self::DISABLED);
        $order->name = '';

        $this->assertSame(self::DELIVERY_ERROR, $this->validator()->getCartValidateError($order, $this->cartDeliveries()));
    }

    /** @return array<int, object> */
    private function cartDeliveries(int ...$extra): array
    {
        $deliveries = [];
        foreach ([self::OFFERED, ...$extra] as $id) {
            $deliveries[$id] = (object)['id' => (string)$id, 'enabled' => '1', 'payment_methods_ids' => [self::METHOD]];
        }

        return $deliveries;
    }

    private function order(int $deliveryId): object
    {
        return (object)[
            'name'              => 'Іван',
            'last_name'         => '',
            'email'             => 'ivan@example.com',
            'phone'             => '',
            'comment'           => '',
            'delivery_id'       => $deliveryId,
            'payment_method_id' => self::METHOD,
        ];
    }

    private function validator(?OrdersHelper $orders = null, bool $translations = true): ValidateHelper
    {
        $settings = new class extends Settings {
            public function __construct() {}
            public function get($param) { return null; }
        };
        $validator = new ReflectionClass(Validator::class)->newInstanceWithoutConstructor();
        new ReflectionProperty(Validator::class, 'settings')->setValue($validator, $settings);
        $request = new class extends Request {
            public function __construct() {}
            public function post($name = null, $type = null, $default = null) { return null; }
        };
        $texts = $translations
            ? ['form_error_delivery' => self::DELIVERY_ERROR, 'form_error_payment_method' => self::PAYMENT_ERROR]
            : [];
        $frontTranslations = new class ($texts) extends FrontTranslations {
            public function __construct(private readonly array $texts) {}
            public function getTranslation($var)
            {
                return $this->texts[$var] ?? null;
            }
        };

        return new ValidateHelper($validator, $settings, $request, $frontTranslations, $orders ?? $this->ordersHelper());
    }

    private function ordersHelper(): OrdersHelper
    {
        $paymentsEntity = $this->createStub(PaymentsEntity::class);
        $paymentsEntity->method('findOne')->willReturnCallback(
            fn (array $filter) => (int)($filter['id'] ?? self::METHOD) === self::METHOD
                && in_array((int)$filter['delivery_id'], self::LINKS, true)
                    ? (object)['id' => self::METHOD]
                    : false
        );

        $entityFactory = $this->createStub(EntityFactory::class);
        $entityFactory->method('get')->willReturnCallback(fn ($class) => match ($class) {
            PaymentsEntity::class => $paymentsEntity,
        });

        $helper = new ReflectionClass(OrdersHelper::class)->newInstanceWithoutConstructor();
        new ReflectionProperty(OrdersHelper::class, 'entityFactory')->setValue($helper, $entityFactory);

        return $helper;
    }
}
