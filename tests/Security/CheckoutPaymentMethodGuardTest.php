<?php

namespace Security;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Оформлення з кошика: прихований вибір прибирається до валідації, а замовлення створюється
 * лише після неї. Сам render() тягне всю вітрину, тож його охороняє перевірка коду.
 */
class CheckoutPaymentMethodGuardTest extends TestCase
{
    public function testHiddenChoiceIsDroppedBeforeValidationAndOrderIsAddedAfterIt(): void
    {
        $body = $this->checkoutBranch();

        $post = strpos($body, '$cartRequest->postOrder()');
        $drop = strpos($body, '$ordersHelper->dropPaymentMethodIfDeliveryOffersNone($order)');
        $validate = strpos($body, '$validateHelper->getCartValidateError($order)');
        $add = strpos($body, '$ordersHelper->add(');

        $this->assertNotFalse($post);
        $this->assertNotFalse($drop, 'оформлення не прибирає прихований вибір способу оплати');
        $this->assertNotFalse($validate);
        $this->assertNotFalse($add);

        $this->assertLessThan($drop, $post);
        $this->assertLessThan($validate, $drop, 'прихований вибір має зникнути до валідації');
        $this->assertLessThan($add, $validate, 'замовлення створюється лише після валідації');
        $this->assertSame(1, substr_count($body, '$ordersHelper->add('), 'другий шлях створення оминає валідацію');
    }

    /** Помилка йде у шаблон текстом, тож ключ має бути в кожній мові кожної теми. */
    #[DataProvider('themeLanguageFiles')]
    public function testErrorTextExistsInEveryThemeLanguage(string $file): void
    {
        $source = file_get_contents($file);
        $this->assertIsString($source);

        $this->assertMatchesRegularExpression("~\\\$lang\\['form_error_payment_method'\\]\\s*=\\s*'[^']+';~", $source, $file);
    }

    public static function themeLanguageFiles(): iterable
    {
        foreach (glob(dirname(__DIR__, 2) . '/design/*/lang/*.php') ?: [] as $file) {
            yield basename(dirname($file, 2)) . '/' . basename($file) => [$file];
        }
    }

    private function checkoutBranch(): string
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/Okay/Controllers/CartController.php');
        $this->assertIsString($source);

        $start = strpos($source, "if (isset(\$_POST['checkout']))");
        $this->assertNotFalse($start);
        $end = strpos($source, 'public function cartAjax(', $start);
        $this->assertNotFalse($end);

        return substr($source, $start, $end - $start);
    }
}
