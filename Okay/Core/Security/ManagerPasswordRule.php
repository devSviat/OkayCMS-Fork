<?php

namespace Okay\Core\Security;

/**
 * Вимоги до пароля менеджера. Одне правило на всі шляхи, де пароль задають:
 * свій профіль, новий менеджер і відновлення через лист.
 */
final class ManagerPasswordRule
{
    public const int MIN_LENGTH = 8;

    public const string EMPTY = 'password_empty';
    public const string SHORT = 'password_short';
    public const string MISMATCH = 'password_mismatch';

    /**
     * @return self::EMPTY|self::SHORT|self::MISMATCH|null null — пароль підходить
     */
    public static function problem(string $password, string $confirmation): ?string
    {
        if (trim($password) === '') {
            return self::EMPTY;
        }
        if (mb_strlen($password) < self::MIN_LENGTH) {
            return self::SHORT;
        }
        if (!hash_equals($password, $confirmation)) {
            return self::MISMATCH;
        }

        return null;
    }
}
