<?php

namespace Okay\Core\Security;

/**
 * Прибирає секрети з оточення контейнера з $_SERVER і $_ENV.
 *
 * PHP-FPM працює з clear_env = no, тож паролі й ключі з оточення лежать у
 * $_SERVER, а шаблон читає його через {$smarty.server.…}. Редагувати шаблони
 * може менеджер без повного доступу. Код бере ці значення через getenv(), а
 * getenv() читає оточення процесу, не масиви, тож прибирання його не зачіпає.
 */
final class EnvSecrets
{
    /** Заголовки запиту (HTTP_*) лишаються: їх надсилає клієнт, і код читає їх саме з $_SERVER. */
    private const string SECRET_NAME = '~^(?!HTTP_)[A-Z0-9_]*(PASSWORD|PASSWD|SECRET|TOKEN|_KEY)$~';

    /**
     * @param array<string, mixed> $server
     * @param array<string, mixed> $env
     */
    public static function scrub(array &$server, array &$env): void
    {
        foreach ([&$server, &$env] as &$values) {
            foreach (array_keys($values) as $name) {
                if (preg_match(self::SECRET_NAME, (string) $name)) {
                    unset($values[$name]);
                }
            }
        }
    }
}
