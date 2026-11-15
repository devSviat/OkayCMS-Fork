<?php

namespace Core;

use Okay\Core\Recaptcha;
use Okay\Core\Settings;
use Okay\Core\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Адреса потрапляє в заголовки листа (To, Reply-To), у посилання mailto: і в CSV-вивантаження,
 * тож дозволено лише «+» і апостроф понад колишній набір: символи зі значенням в URL (`?`, `%`,
 * `=`, `&`, `#`, `/`) і формулах таблиць на початку адреси лишаються забороненими.
 *
 * Без модифікатора D «$» у регулярці пропускав перенос рядка в кінці: акаунт
 * «victim@x.com\n» реєструвався поруч зі справжнім, а код, що обрізає
 * пробіли, бачив у ньому ту саму адресу.
 */
class ValidatorIsEmailTest extends TestCase
{
    private Validator $validator;

    protected function setUp(): void
    {
        $this->validator = new Validator($this->createStub(Settings::class), $this->createStub(Recaptcha::class));
    }

    /** @return array<string, array{0: string, 1: bool}> */
    public static function emailProvider(): array
    {
        return [
            'звичайна' => ['buyer@example.com', true],
            'з крапкою й дефісом' => ['first.last-1@shop-x.example.ua', true],
            'перенос рядка в кінці' => ["victim@example.com\n", false],
            'перенос рядка всередині' => ["victim@example.com\nbcc@x.com", false],
            'без домену' => ['buyer@', false],
            'плюс-адресація' => ['buyer+orders@example.com', true],
            'апостроф' => ["o'brien@example.com", true],
            'punycode TLD' => ['buyer@xn--80ak6aa92e.xn--j1amh', true],
            'кирилиця в домені' => ['buyer@приклад.укр', false],
            'довга s з Unicode-регістру' => ["\u{017F}hop@example.com", false],
            'знак Кельвіна' => ["\u{212A}ey@example.com", false],
            'плюс на початку' => ['+cmd@example.com', false],
            'рівність на початку' => ['=cmd@example.com', false],
            'знак питання' => ['buyer?cc=other@example.com', false],
            'відсоток' => ['buyer%0d@example.com', false],
            'кома' => ['a@example.com,b@example.com', false],
            'пробіл' => ['buyer @example.com', false],
            'кутові дужки' => ['<b>@example.com', false],
            'дві крапки поспіль' => ['a..b@example.com', false],
            'крапка в кінці локальної частини' => ['buyer.@example.com', false],
            'без TLD' => ['buyer@localhost', false],
            'апостроф і перенос рядка' => ["o'brien@example.com\r\nBcc: x@example.com", false],
        ];
    }

    #[DataProvider('emailProvider')]
    public function testIsEmail(string $email, bool $expected): void
    {
        $this->assertSame($expected, $this->validator->isEmail($email), json_encode($email));
    }
}
