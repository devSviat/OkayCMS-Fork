<?php

namespace Okay\Modules\OkayCMS\NovaposhtaCost\Helpers;

use Okay\Core\Modules\Extender\ExtenderFacade;

/**
 * Ціна доставки Нової Пошти, розрахована сервером для кошика покупця.
 *
 * Модуль рахує ціну через AJAX ще до оформлення. Розрахунок кладе
 * ціну в сесію, прив'язавши її до способу доставки, параметрів розрахунку
 * (місто, відділення, післяплата…) і складу кошика, а оформлення бере її звідси
 * лише за повного збігу — даним із браузера ціна доставки не довіряється.
 */
class NPDeliveryQuoteHelper
{
    private const string SESSION_KEY = 'novaposhta_delivery_quotes';

    /** Тарифи НП змінюються; давній розрахунок при оформленні не береться. */
    private const int TTL_SECONDS = 7200;

    /**
     * @param string $scope   хто рахує, наприклад 'novaposhta:3' (модуль і спосіб доставки)
     * @param array  $context усе, від чого залежить ціна, крім кошика
     * @param object $cart    результат Cart::get()
     */
    public function remember(string $scope, array $context, object $cart, float $price): void
    {
        if ($price < 0 || !is_finite($price)) {
            ExtenderFacade::execute(__METHOD__, null, func_get_args());
            return;
        }

        $_SESSION[self::SESSION_KEY][$scope] = [
            'key' => $this->key($context, $cart),
            'price' => $price,
            'expires' => time() + self::TTL_SECONDS,
        ];

        ExtenderFacade::execute(__METHOD__, null, func_get_args());
    }

    /**
     * Ціна, збережена для тих самих параметрів і того самого кошика, або null.
     */
    public function find(string $scope, array $context, object $cart): ?float
    {
        $quote = $_SESSION[self::SESSION_KEY][$scope] ?? null;

        $price = null;
        if (is_array($quote)
            && isset($quote['key'], $quote['price'], $quote['expires'])
            && (int)$quote['expires'] >= time()
            && hash_equals((string)$quote['key'], $this->key($context, $cart))
        ) {
            $price = (float)$quote['price'];
        }

        return ExtenderFacade::execute(__METHOD__, $price, func_get_args());
    }

    private function key(array $context, object $cart): string
    {
        ksort($context);

        $items = [];
        foreach ($cart->purchases ?? [] as $purchase) {
            $variantId = (int)($purchase->variant->id ?? $purchase->variant_id ?? 0);
            $items[$variantId] = ($items[$variantId] ?? 0) + (int)($purchase->amount ?? 0);
        }
        ksort($items);

        return hash('sha256', json_encode([
            array_map(strval(...), $context),
            $items,
            round((float)($cart->total_price ?? 0), 2),
        ]));
    }
}
