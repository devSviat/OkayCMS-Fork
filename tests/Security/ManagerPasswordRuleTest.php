<?php

namespace Security;

use Okay\Core\Security\ManagerPasswordRule;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Одне правило для всіх місць, де менеджер задає пароль: свій профіль, новий
 * менеджер і відновлення через лист. Коли перевірки жили в кожному контролері
 * окремо, відновлення пропускало пароль з одного символу.
 */
class ManagerPasswordRuleTest extends TestCase
{
    /** @return iterable<string, array{string, string, ?string}> */
    public static function cases(): iterable
    {
        yield 'empty' => ['', '', ManagerPasswordRule::EMPTY];
        yield 'only spaces' => ['        ', '        ', ManagerPasswordRule::EMPTY];
        yield 'too short' => ['abc', 'abc', ManagerPasswordRule::SHORT];
        yield 'one below the minimum' => ['1234567', '1234567', ManagerPasswordRule::SHORT];
        yield 'multibyte counts characters' => ['пароль12', 'пароль12', null];
        yield 'mismatch' => ['long-enough-1', 'long-enough-2', ManagerPasswordRule::MISMATCH];
        yield 'short wins over mismatch' => ['abc', 'abd', ManagerPasswordRule::SHORT];
        yield 'ok' => ['long-enough-1', 'long-enough-1', null];
    }

    #[DataProvider('cases')]
    public function testProblem(string $password, string $confirmation, ?string $expected): void
    {
        $this->assertSame($expected, ManagerPasswordRule::problem($password, $confirmation));
    }

    public function testMinimumIsEight(): void
    {
        $this->assertSame(8, ManagerPasswordRule::MIN_LENGTH);
    }
}
