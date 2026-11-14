<?php

namespace Security;

use Okay\Core\Security\EnvSecrets;
use PHPUnit\Framework\TestCase;

/**
 * PHP-FPM працює з clear_env = no, тож змінні оточення контейнера лежать у
 * $_SERVER. Шаблон читає $_SERVER через {$smarty.server.…}, а редагувати шаблони
 * може менеджер без повного доступу. Код бере ці значення через getenv(), тож
 * із суперглобальних масивів їх можна прибрати.
 */
class EnvSecretsTest extends TestCase
{
    public function testSecretsLeaveTheSuperglobalsButStayInGetenv(): void
    {
        $server = [
            'MYSQL_PASSWORD' => 'p', 'REDIS_PASSWORD' => 'r', 'OKAY_TOTP_KEY' => 'k',
            'REDIS_SIGNING_KEY' => 's', 'SOME_API_TOKEN' => 't', 'APP_SECRET' => 'a',
            'MYSQL_HOST' => 'db', 'APP_ENV' => 'prod', 'REQUEST_URI' => '/x',
            'HTTP_X_CORE_UPDATER_TOKEN' => 'header', 'HTTP_X_API_KEY' => 'header',
        ];
        $env = $server;

        EnvSecrets::scrub($server, $env);

        $kept = ['MYSQL_HOST', 'APP_ENV', 'REQUEST_URI', 'HTTP_X_CORE_UPDATER_TOKEN', 'HTTP_X_API_KEY'];
        $this->assertSame($kept, array_keys($server));
        $this->assertSame($kept, array_keys($env));
    }
}
