<?php

namespace Security;

use Okay\Core\FrontTranslations;
use Okay\Core\Request;
use Okay\Core\Settings;
use Okay\Core\Validator;
use Okay\Helpers\ValidateHelper;
use PHPUnit\Framework\TestCase;

/**
 * Ім'я, email і телефон перевіряються на теги, а прізвище — ні, тож воно
 * доходило до адмінки з будь-якою розміткою (історія замовлення вставляє його
 * як є). Помилка та сама, що для імені: тема іншої не показує.
 */
class LastNameMarkupTest extends TestCase
{
    private const string MARKUP = 'Іванов<img src=x onerror=alert(1)>';

    public function testCheckoutRejectsMarkupInLastName(): void
    {
        $order = (object) ['name' => 'Іван', 'last_name' => self::MARKUP, 'email' => 'ivan@example.com', 'phone' => '', 'comment' => ''];

        $this->assertSame('empty_name', $this->helper()->getCartValidateError($order));
    }

    public function testCheckoutAcceptsAnOrdinaryLastName(): void
    {
        $order = (object) ['name' => 'Іван', 'last_name' => "О'Брайен-Петренко", 'email' => 'ivan@example.com', 'phone' => '', 'comment' => ''];

        $this->assertNull($this->helper()->getCartValidateError($order));
    }

    public function testRegistrationAndProfileRejectMarkupInLastName(): void
    {
        $user = (object) ['name' => 'Іван', 'last_name' => self::MARKUP, 'email' => 'nobody-' . bin2hex(random_bytes(4)) . '@example.com', 'phone' => '', 'password' => 'x'];

        try {
            $this->assertSame('empty_name', $this->helper()->getUserRegisterError($user));
            $this->assertSame('empty_name', $this->helper()->getUserError($user, 0));
        } catch (\PDOException $e) {
            $this->markTestSkipped('немає бази: ' . $e->getMessage());
        }
    }

    private function helper(): ValidateHelper
    {
        $settings = new class extends Settings {
            public function __construct() {}
            public function get($param) { return null; }
        };
        $validator = new \ReflectionClass(Validator::class)->newInstanceWithoutConstructor();
        $settingsProperty = new \ReflectionProperty(Validator::class, 'settings');
        $settingsProperty->setValue($validator, $settings);
        $request = new class extends Request {
            public function __construct() {}
            public function post($name = null, $type = null, $default = null) { return null; }
        };
        $translations = new \ReflectionClass(FrontTranslations::class)->newInstanceWithoutConstructor();

        return new ValidateHelper($validator, $settings, $request, $translations);
    }
}
