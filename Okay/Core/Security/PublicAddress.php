<?php

namespace Okay\Core\Security;

/**
 * Чи можна серверу звертатись на цю IP-адресу від імені користувача.
 * Приватні, локальні й службові діапазони закриті: за ними внутрішні сервіси.
 */
final class PublicAddress
{
    /** 100.64.0.0/10: FILTER_FLAG_NO_RES_RANGE його не відсікає, а за ним бувають внутрішні адреси провайдера. */
    private const string CGNAT_PREFIX = '100.64.0.0';
    private const int CGNAT_BITS = 10;

    public static function isPublic(string $ip): bool
    {
        $ip = self::unmapV4($ip);

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }

        return !(filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false
            && self::inV4Range($ip, self::CGNAT_PREFIX, self::CGNAT_BITS));
    }

    /** ::ffff:10.0.0.1 — та сама IPv4-адреса, і перевіряти її треба як IPv4. */
    private static function unmapV4(string $ip): string
    {
        if (stripos($ip, '::ffff:') === 0) {
            $tail = substr($ip, 7);
            if (filter_var($tail, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
                return $tail;
            }
        }

        return $ip;
    }

    private static function inV4Range(string $ip, string $prefix, int $bits): bool
    {
        $mask = -1 << (32 - $bits);

        return (ip2long($ip) & $mask) === (ip2long($prefix) & $mask);
    }
}
