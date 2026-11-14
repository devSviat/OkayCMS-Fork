<?php


namespace Okay\Core;


/**
 * Способи оплати для шаблонів вітрини й листів.
 *
 * `settings` способу оплати містять ключі платіжних сервісів, тому в шаблон вони не йдуть
 * зовсім, а не лише змінні типу secret: модулі без цього типу тримають ключі звичайним
 * текстом. Модулі читають налаштування на сервері через PaymentsEntity::getPaymentSettings().
 */
final class StorefrontPaymentMethods
{
    /**
     * Прибирає `settings` з одного способу оплати чи зі списку, змінюючи обʼєкти на місці.
     * Порожній результат вибірки (null, false, []) повертається як є.
     *
     * @template T
     * @param T $methods
     * @return T
     */
    public static function withoutSettings(mixed $methods): mixed
    {
        if (is_object($methods)) {
            unset($methods->settings);
        } elseif (is_iterable($methods)) {
            foreach ($methods as $method) {
                if (is_object($method)) {
                    unset($method->settings);
                }
            }
        }

        return $methods;
    }
}
