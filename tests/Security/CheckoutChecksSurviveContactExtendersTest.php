<?php

namespace Security;

use Okay\Core\EntityFactory;
use Okay\Core\FrontTranslations;
use Okay\Core\Modules\Extender\ChainExtender;
use Okay\Core\Modules\Extender\ExtensionInterface;
use Okay\Core\Request;
use Okay\Core\Settings;
use Okay\Core\Validator;
use Okay\Entities\PaymentsEntity;
use Okay\Helpers\OrdersHelper;
use Okay\Helpers\ValidateHelper;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;

/**
 * Модуль контактів магазину знімає помилку порожнього email ланкою на getCartValidateError(),
 * тож порожній email там — звичайне оформлення. Перевірки доставки, способу оплати й капчі
 * не можуть залежати від того, чи якась ланка зняла помилку контактів.
 */
class CheckoutChecksSurviveContactExtendersTest extends TestCase
{
    private const string TRIGGER = ValidateHelper::class . '::getCartValidateError';

    private const int DELIVERY = 3;
    private const int DISABLED_DELIVERY = 2;
    private const int METHOD = 18;
    private const int UNLINKED_METHOD = 27;

    protected function setUp(): void
    {
        new ChainExtender()->newExtension(ValidateHelper::class, 'getCartValidateError', EmailNotRequired::class, 'emailNotRequired');
    }

    protected function tearDown(): void
    {
        self::forget(self::TRIGGER);
    }

    private static function forget(string $trigger): void
    {
        \Closure::bind(static function () use ($trigger): void {
            unset(ChainExtender::$triggers[$trigger]);
        }, null, ChainExtender::class)();
    }

    public function testEmptyEmailCheckoutStillPasses(): void
    {
        $this->assertEmpty($this->validator()->getCartValidateError($this->order(), $this->cartDeliveries()));
    }

    public function testEmptyEmailDoesNotOpenADisabledDelivery(): void
    {
        $order = $this->order(deliveryId: self::DISABLED_DELIVERY);

        $this->assertSame('delivery', $this->validator()->getCartValidateError($order, $this->cartDeliveries()));
    }

    public function testEmptyEmailDoesNotOpenAnUnlinkedPaymentMethod(): void
    {
        $order = $this->order(paymentMethodId: self::UNLINKED_METHOD);

        $this->assertSame('payment_method', $this->validator()->getCartValidateError($order, $this->cartDeliveries()));
    }

    public function testEmptyEmailDoesNotSkipTheCaptcha(): void
    {
        $this->assertSame('captcha', $this->validator(captchaOn: true, captchaPasses: false)->getCartValidateError($this->order(), $this->cartDeliveries()));
        $this->assertEmpty(
            $this->validator(captchaOn: true, captchaPasses: true)->getCartValidateError($this->order(), $this->cartDeliveries()),
            'control: the right captcha'
        );
    }

    /** Ланка, що знімає будь-яку помилку, знімає лише помилку контактів. */
    public function testAnExtenderCannotClearASecurityError(): void
    {
        new ChainExtender()->newExtension(ValidateHelper::class, 'getCartValidateError', EmailNotRequired::class, 'clearEverything');

        $order = $this->order(deliveryId: self::DISABLED_DELIVERY);
        $order->name = '';

        $this->assertSame('delivery', $this->validator()->getCartValidateError($order, $this->cartDeliveries()));
        $this->assertEmpty(
            $this->validator()->getCartValidateError($this->order(name: ''), $this->cartDeliveries()),
            'control: the contact error is still the extenders to clear'
        );
    }

    /** Розширення security-перевірки може додати помилку, але не прибрати помилку ядра. */
    public function testSecurityExtenderAddsButNeverClears(): void
    {
        $trigger = ValidateHelper::class . '::getCartSecurityError';
        new ChainExtender()->newExtension(ValidateHelper::class, 'getCartSecurityError', EmailNotRequired::class, 'clearEverything');
        try {
            $this->assertSame('delivery', $this->validator()->getCartSecurityError($this->order(deliveryId: self::DISABLED_DELIVERY), $this->cartDeliveries()));
        } finally {
            self::forget($trigger);
        }

        new ChainExtender()->newExtension(ValidateHelper::class, 'getCartSecurityError', EmailNotRequired::class, 'refuseEverything');
        try {
            $this->assertSame('module_says_no', $this->validator()->getCartSecurityError($this->order(), $this->cartDeliveries()));
        } finally {
            self::forget($trigger);
        }
    }

    private function order(int $deliveryId = self::DELIVERY, int $paymentMethodId = self::METHOD, string $name = 'Іван'): object
    {
        return (object)[
            'name'              => $name,
            'last_name'         => '',
            'email'             => '',
            'phone'             => '+380671112233',
            'comment'           => '',
            'delivery_id'       => $deliveryId,
            'payment_method_id' => $paymentMethodId,
        ];
    }

    /** @return array<int, object> */
    private function cartDeliveries(): array
    {
        return [self::DELIVERY => (object)['id' => self::DELIVERY, 'payment_methods_ids' => [self::METHOD]]];
    }

    private function validator(bool $captchaOn = false, bool $captchaPasses = true): ValidateHelper
    {
        $settings = new class ($captchaOn) extends Settings {
            public function __construct(private readonly bool $captchaOn) {}
            public function get($param) { return $param === 'captcha_cart' ? $this->captchaOn : null; }
        };
        $validator = new class ($captchaPasses) extends Validator {
            public function __construct(private readonly bool $passes) {}
            public function verifyCaptcha($form, $captcha_code = '') { return $this->passes; }
        };
        new ReflectionProperty(Validator::class, 'settings')->setValue($validator, $settings);
        $request = new class extends Request {
            public function __construct() {}
            public function post($name = null, $type = null, $default = null) { return null; }
        };
        $translations = new class extends FrontTranslations {
            public function __construct() {}
            public function getTranslation($var) { return null; }
        };

        return new ValidateHelper($validator, $settings, $request, $translations, $this->ordersHelper());
    }

    /** Спосіб METHOD повʼязаний з обома доставками, UNLINKED_METHOD — з жодною. */
    private function ordersHelper(): OrdersHelper
    {
        $paymentsEntity = $this->createStub(PaymentsEntity::class);
        $paymentsEntity->method('findOne')->willReturnCallback(
            fn (array $filter) => (int)($filter['id'] ?? self::METHOD) === self::METHOD ? (object)['id' => self::METHOD] : false
        );
        $entityFactory = $this->createStub(EntityFactory::class);
        $entityFactory->method('get')->willReturn($paymentsEntity);

        $helper = new ReflectionClass(OrdersHelper::class)->newInstanceWithoutConstructor();
        new ReflectionProperty(OrdersHelper::class, 'entityFactory')->setValue($helper, $entityFactory);

        return $helper;
    }
}

/** Те саме, що робить ланка модуля контактів: порожній email у кошику не помилка. */
class EmailNotRequired implements ExtensionInterface
{
    public function emailNotRequired($error, $order = null)
    {
        return $error === 'empty_email' ? '' : $error;
    }

    public function clearEverything($error, ...$args)
    {
        return null;
    }

    public function refuseEverything($error, ...$args)
    {
        return $error ?? 'module_says_no';
    }
}
