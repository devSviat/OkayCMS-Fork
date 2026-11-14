<?php

namespace Core\Modules;

use Okay\Core\Modules\SecretSettings;
use PHPUnit\Framework\TestCase;

/**
 * Секретне поле форми способу оплати завжди приходить порожнім, бо сторінка
 * не друкує збережене значення. Тож порожнє поле означає «не змінювати», а
 * стерти ключ можна лише окремою позначкою.
 */
class SecretSettingsTest extends TestCase
{
    private const array SECRETS = ['vendor__private_key'];

    public function testEmptyKeepsStored(): void
    {
        $merged = SecretSettings::merge(
            ['vendor__private_key' => ''],
            ['vendor__private_key' => 'stored-0000'],
            self::SECRETS,
            []
        );

        $this->assertSame('stored-0000', $merged['vendor__private_key']);
    }

    public function testWhitespaceKeepsStored(): void
    {
        $merged = SecretSettings::merge(
            ['vendor__private_key' => "  \t "],
            ['vendor__private_key' => 'stored-0000'],
            self::SECRETS,
            []
        );

        $this->assertSame('stored-0000', $merged['vendor__private_key']);
    }

    public function testNewValueReplaces(): void
    {
        $merged = SecretSettings::merge(
            ['vendor__private_key' => "  new-1111\n"],
            ['vendor__private_key' => 'stored-0000'],
            self::SECRETS,
            []
        );

        $this->assertSame('new-1111', $merged['vendor__private_key']);
    }

    public function testClearEmpties(): void
    {
        $merged = SecretSettings::merge(
            ['vendor__private_key' => ''],
            ['vendor__private_key' => 'stored-0000'],
            self::SECRETS,
            ['vendor__private_key']
        );

        $this->assertSame('', $merged['vendor__private_key']);
    }

    /** Позначка «Стерти» сильніша за вписане поруч нове значення. */
    public function testClearWinsOverNewValue(): void
    {
        $merged = SecretSettings::merge(
            ['vendor__private_key' => 'new-1111'],
            ['vendor__private_key' => 'stored-0000'],
            self::SECRETS,
            ['vendor__private_key']
        );

        $this->assertSame('', $merged['vendor__private_key']);
    }

    /** Позначка «Стерти» без самого поля не додає порожній ключ у налаштування. */
    public function testClearOfAbsentSecretAddsNothing(): void
    {
        $merged = SecretSettings::merge(
            ['other__field' => 'x'],
            ['vendor__private_key' => 'stored-0000'],
            self::SECRETS,
            ['vendor__private_key']
        );

        $this->assertSame(['other__field' => 'x'], $merged);
    }

    public function testNonSecretUntouched(): void
    {
        $merged = SecretSettings::merge(
            ['vendor__public_key' => '', 'vendor__mode' => ' hold '],
            ['vendor__public_key' => 'stored-public', 'vendor__mode' => 'pay'],
            self::SECRETS,
            ['vendor__public_key']
        );

        $this->assertSame(['vendor__public_key' => '', 'vendor__mode' => ' hold '], $merged);
    }

    /**
     * Поля прихованих модулів форма не надсилає. Коли спосіб оплати переводять
     * на інший модуль, ключ старого модуля має зникнути, як і решта його полів.
     */
    public function testAbsentSecretIsNotResurrected(): void
    {
        $merged = SecretSettings::merge(
            ['other__field' => 'x'],
            ['vendor__private_key' => 'stored-0000'],
            self::SECRETS,
            []
        );

        $this->assertSame(['other__field' => 'x'], $merged);
    }

    public function testNonStringPostedSecretKeepsStored(): void
    {
        $merged = SecretSettings::merge(
            ['vendor__private_key' => ['injected']],
            ['vendor__private_key' => 'stored-0000'],
            self::SECRETS,
            []
        );

        $this->assertSame('stored-0000', $merged['vendor__private_key']);
    }
}
