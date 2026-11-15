<?php

namespace Security;

use PHPUnit\Framework\TestCase;

/**
 * Зміна способу оплати на сторінці замовлення: перевірка походження стоїть до рішення
 * хелпера, запис іде лише після дозволу, а відповідь завжди 303 (PRG).
 * Сам контролер завершується exit, тож його охороняє перевірка коду.
 */
class OrderPaymentMethodGuardTest extends TestCase
{
    public function testOriginCheckPrecedesTheDecisionAndTheRedirect(): void
    {
        $body = $this->renderBody();

        $origin = strpos($body, '$this->requireSameOrigin(');
        $decision = strpos($body, '$ordersHelper->canChangePaymentMethod(');
        $update = strpos($body, "\$ordersEntity->update(\$order->id, ['payment_method_id'");
        $redirect = strpos($body, '->redirectTo(');

        $this->assertNotFalse($origin, 'render() не викликає requireSameOrigin()');
        $this->assertNotFalse($decision, 'render() не питає canChangePaymentMethod()');
        $this->assertNotFalse($update);
        $this->assertNotFalse($redirect, 'render() не робить редирект після POST');

        $this->assertLessThan($decision, $origin, 'requireSameOrigin() має стояти до canChangePaymentMethod()');
        $this->assertLessThan($update, $decision, 'запис способу оплати має стояти після canChangePaymentMethod()');
        $this->assertLessThan($redirect, $update);
        $this->assertSame(1, substr_count($body, "\$ordersEntity->update(\$order->id, ['payment_method_id'"), 'другий шлях запису оминає перевірку');
        $this->assertMatchesRegularExpression('~->redirectTo\([^;]*,\s*303\);~', $body);
    }

    private function renderBody(): string
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/Okay/Controllers/OrderController.php');
        $this->assertIsString($source);

        $start = strpos($source, 'public function render(');
        $this->assertNotFalse($start);

        return substr($source, $start);
    }
}
