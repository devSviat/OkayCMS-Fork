<?php

namespace Security;

use Okay\Core\Security\PublicAddress;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Фото товару за URL сервер завантажує сам. Без цієї перевірки менеджер з правом
 * на товари міг змусити його звертатись до внутрішніх сервісів: контейнерів у
 * docker-мережі, localhost, адреси метаданих хмари.
 */
class PublicAddressTest extends TestCase
{
    /** @return iterable<string, array{string, bool}> */
    public static function addresses(): iterable
    {
        yield 'public v4' => ['93.184.216.34', true];
        yield 'public v6' => ['2606:2800:220:1:248:1893:25c8:1946', true];
        yield 'loopback' => ['127.0.0.1', false];
        yield 'docker network 172' => ['172.18.0.5', false];
        yield 'private 10' => ['10.0.0.7', false];
        yield 'private 192.168' => ['192.168.1.10', false];
        yield 'link-local metadata' => ['169.254.169.254', false];
        yield 'this network' => ['0.0.0.0', false];
        yield 'carrier-grade nat' => ['100.64.0.1', false];
        yield 'v6 loopback' => ['::1', false];
        yield 'v6 unique local' => ['fd00::1', false];
        yield 'v6 link-local' => ['fe80::1', false];
        yield 'v4-mapped loopback' => ['::ffff:127.0.0.1', false];
        yield 'v4-mapped private' => ['::ffff:10.0.0.1', false];
        yield 'not an address' => ['localhost', false];
        yield 'empty' => ['', false];
    }

    #[DataProvider('addresses')]
    public function testIsPublic(string $ip, bool $public): void
    {
        $this->assertSame($public, PublicAddress::isPublic($ip));
    }
}
