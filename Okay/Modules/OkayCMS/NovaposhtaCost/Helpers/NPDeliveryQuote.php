<?php

namespace Okay\Modules\OkayCMS\NovaposhtaCost\Helpers;

/**
 * Параметри, від яких залежить ціна Нової Пошти, — однаково для розрахунку
 * (ajax/np/calc) і для оформлення замовлення, щоб DeliveryQuoteHelper знайшов
 * розраховану ціну лише за тих самих умов.
 */
final class NPDeliveryQuote
{
    public static function scope(int $deliveryId): string
    {
        return 'novaposhta:' . $deliveryId;
    }

    public static function context(?string $cityRef, bool $redelivery): array
    {
        return [
            'city' => trim((string)$cityRef),
            'redelivery' => $redelivery ? 1 : 0,
        ];
    }
}
