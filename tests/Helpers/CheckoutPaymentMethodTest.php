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
 * Оформлення з кошика бере payment_method_id із POST. Спосіб має бути з того самого списку,
 * що й на сторінці замовлення: увімкнений і повʼязаний з обраною доставкою.
 */
class CheckoutPaymentMethodTest extends TestCase
{
    private const int DELIVERY = 3;
    private const int OTHER_DELIVERY = 4;
    private const int DELIVERY_WITHOUT_PAYMENTS = 5;

    private const int OFFERED = 10;
    private const int DISABLED = 11;
    private const int FOR_OTHER_DELIVERY = 12;
    private const int DISABLED_ONLY = 13;

    private const string ERROR_TEXT = 'Оберіть спосіб оплати зі списку';

    /**
     * Спосіб оплати → [enabled, доставки, з якими він повʼязаний].
     * Фейк застосовує лише ті фільтри, які передав помічник: пропущений фільтр
     * робить відповідний тест червоним, а не випадково зеленим.
     */
    private const array METHODS = [
        self::OFFERED            => [1, [self::DELIVERY]],
        self::DISABLED           => [0, [self::DELIVERY]],
        self::FOR_OTHER_DELIVERY => [1, [self::OTHER_DELIVERY]],
        self::DISABLED_ONLY      => [0, [self::DELIVERY_WITHOUT_PAYMENTS]],
    ];

    public function testOfferedMethodPasses(): void
    {
        $this->assertNull($this->validator()->getCartValidateError($this->order(self::OFFERED), self::cartDeliveries()));
    }

    public function testDisabledMethodIsAnError(): void
    {
        $this->assertSame(self::ERROR_TEXT, $this->validator()->getCartValidateError($this->order(self::DISABLED), self::cartDeliveries()));
    }

    public function testMethodOfAnotherDeliveryIsAnError(): void
    {
        $this->assertSame(self::ERROR_TEXT, $this->validator()->getCartValidateError($this->order(self::FOR_OTHER_DELIVERY), self::cartDeliveries()));
        $this->assertNull(
            $this->validator()->getCartValidateError($this->order(self::FOR_OTHER_DELIVERY, self::OTHER_DELIVERY), self::cartDeliveries()),
            'control: the same method with its own delivery'
        );
    }

    /** Доставку й спосіб підмінено в одному POST: рішення бере доставку саме з цього замовлення. */
    public function testDeliverySwitchedInTheSamePostIsCheckedAgainstThatDelivery(): void
    {
        $this->assertSame(self::ERROR_TEXT, $this->validator()->getCartValidateError($this->order(self::OFFERED, self::OTHER_DELIVERY), self::cartDeliveries()));
    }

    #[DataProvider('foreignIds')]
    public function testUnknownOrNegativeIdIsAnError(int $id): void
    {
        $this->assertSame(self::ERROR_TEXT, $this->validator()->getCartValidateError($this->order($id), self::cartDeliveries()));
    }

    public static function foreignIds(): array
    {
        return ['unknown' => [999], 'negative' => [-self::OFFERED]];
    }

    /** Ядро ніколи не вимагало способу оплати при оформленні, тож замовлення без нього проходить. */
    #[DataProvider('missingMethods')]
    public function testMissingMethodPassesAsBefore(array $fields): void
    {
        $order = $this->order(self::OFFERED);
        unset($order->payment_method_id);
        foreach ($fields as $name => $value) {
            $order->$name = $value;
        }

        $this->assertNull($this->validator()->getCartValidateError($order, self::cartDeliveries()));
    }

    public static function missingMethods(): array
    {
        return [
            'not posted'   => [['payment_method_id' => 0]],
            'null'         => [['payment_method_id' => null]],
            'absent'       => [[]],
            'no delivery'  => [['payment_method_id' => 0, 'delivery_id' => 0]],
        ];
    }

    /** Без ключа в темі помилка однаково має бути: null тут означав би «перевірку пройдено». */
    public function testErrorSurvivesAThemeWithoutTheTranslation(): void
    {
        $error = $this->validator(translation: null)->getCartValidateError($this->order(self::DISABLED), self::cartDeliveries());

        $this->assertNotNull($error);
        $this->assertNotSame('', $error);
    }

    /** Перевірки покупця йдуть раніше: тема показує ту помилку, яку вміє назвати. */
    public function testContactErrorsStillComeFirst(): void
    {
        $order = $this->order(self::DISABLED);
        $order->name = '';

        $this->assertSame('empty_name', $this->validator()->getCartValidateError($order, self::cartDeliveries()));
    }

    public function testValidatorDoesNotAskTheHelperWithoutAMethod(): void
    {
        $orders = $this->createMock(OrdersHelper::class);
        $orders->expects($this->never())->method('isPaymentMethodOffered');

        $this->assertNull($this->validator(orders: $orders)->getCartValidateError($this->order(0), self::cartDeliveries()));
    }

    public function testValidatorAsksTheHelperWithTheOrderAndTheMethod(): void
    {
        $order = $this->order(self::OFFERED);
        $orders = $this->createMock(OrdersHelper::class);
        $orders->expects($this->once())->method('isPaymentMethodOffered')->with($order, self::OFFERED)->willReturn(false);

        $this->assertSame(self::ERROR_TEXT, $this->validator(orders: $orders)->getCartValidateError($order, self::cartDeliveries()));
    }

    /**
     * Доставка без способів оплати: тема ховає блок оплати, але позначена прихована радіокнопка
     * все одно надсилається. Покупець вибору не бачив, тож замовлення лишається без способу.
     */
    public function testDeliveryWithoutPaymentsDropsTheHiddenChoice(): void
    {
        $order = $this->ordersHelper()->dropPaymentMethodIfDeliveryOffersNone($this->order(self::OFFERED, self::DELIVERY_WITHOUT_PAYMENTS));

        $this->assertSame(0, $order->payment_method_id);
        $this->assertNull($this->validator()->getCartValidateError($order, self::cartDeliveries()));
    }

    public function testOrderWithoutDeliveryDropsTheChoice(): void
    {
        $order = $this->ordersHelper()->dropPaymentMethodIfDeliveryOffersNone($this->order(self::DISABLED, 0));

        $this->assertSame(0, $order->payment_method_id);
    }

    /** Коли доставка щось пропонує, недозволений спосіб лишається, щоб валідація назвала помилку. */
    #[DataProvider('choicesOnADeliveryWithPayments')]
    public function testDeliveryWithPaymentsKeepsThePostedChoice(int $id): void
    {
        $order = $this->ordersHelper()->dropPaymentMethodIfDeliveryOffersNone($this->order($id));

        $this->assertSame($id, $order->payment_method_id);
    }

    public static function choicesOnADeliveryWithPayments(): array
    {
        return ['offered' => [self::OFFERED], 'disabled' => [self::DISABLED], 'foreign' => [self::FOR_OTHER_DELIVERY], 'unknown' => [999]];
    }

    public function testOfferedRuleIsSharedWithTheOrderPage(): void
    {
        $helper = $this->ordersHelper();
        $unpaid = (object)['id' => 5, 'paid' => '0', 'delivery_id' => self::DELIVERY];

        foreach (array_keys(self::METHODS) as $id) {
            $this->assertSame(
                $helper->canChangePaymentMethod($unpaid, $id),
                $helper->isPaymentMethodOffered($this->order($id), $id),
                'method ' . $id
            );
        }
    }

    /**
     * Доставки, які кошик пропонує (як їх віддає DeliveriesHelper::getCartDeliveriesList()):
     * вимкненої DISABLED_DELIVERY тут немає.
     *
     * @return array<int, object>
     */
    private static function cartDeliveries(): array
    {
        $deliveries = [];
        foreach ([self::DELIVERY, self::OTHER_DELIVERY, self::DELIVERY_WITHOUT_PAYMENTS] as $id) {
            $deliveries[$id] = (object)['id' => $id];
        }

        return $deliveries;
    }

    private function order(int $paymentMethodId, int $deliveryId = self::DELIVERY): object
    {
        return (object)[
            'name'              => 'Іван',
            'last_name'         => '',
            'email'             => 'ivan@example.com',
            'phone'             => '',
            'comment'           => '',
            'delivery_id'       => $deliveryId,
            'payment_method_id' => $paymentMethodId,
        ];
    }

    private function validator(?OrdersHelper $orders = null, ?string $translation = self::ERROR_TEXT): ValidateHelper
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
        $translations = new class ($translation) extends FrontTranslations {
            public function __construct(private readonly ?string $text) {}
            public function getTranslation($var)
            {
                return $var === 'form_error_payment_method' ? $this->text : null;
            }
        };

        return new ValidateHelper($validator, $settings, $request, $translations, $orders ?? $this->ordersHelper());
    }

    private function ordersHelper(): OrdersHelper
    {
        $paymentsEntity = $this->createStub(PaymentsEntity::class);
        $paymentsEntity->method('find')->willReturnCallback(fn (array $filter) => self::matching($filter));
        $paymentsEntity->method('findOne')->willReturnCallback(
            fn (array $filter) => self::matching($filter)[0] ?? false
        );

        $entityFactory = $this->createStub(EntityFactory::class);
        $entityFactory->method('get')->willReturnCallback(fn ($class) => match ($class) {
            PaymentsEntity::class => $paymentsEntity,
        });

        $helper = new ReflectionClass(OrdersHelper::class)->newInstanceWithoutConstructor();
        new ReflectionProperty(OrdersHelper::class, 'entityFactory')->setValue($helper, $entityFactory);

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
            $rows[] = (object)['id' => $id, 'enabled' => (string)$enabled];
        }

        return $rows;
    }
}
