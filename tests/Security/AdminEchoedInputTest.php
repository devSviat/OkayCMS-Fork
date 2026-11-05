<?php

namespace Security;

use PHPUnit\Framework\TestCase;

class AdminEchoedInputTest extends TestCase
{
    /**
     * Без сесії адміна $manager — null: читання ->lang до перевірки давало
     * warning, а trigger_error(E_USER_ERROR) — 500 замість відмови.
     */
    public function testConfigureRefusesAnonymousBeforeTouchingTheManager()
    {
        $source = $this->source('backend/ajax/configure.php');

        $guard = strpos($source, 'http_response_code(403)');
        $this->assertNotFalse($guard, 'configure.php має відповідати 403 без входу');
        $this->assertLessThan(strpos($source, '$manager->lang'), $guard);
        $this->assertStringNotContainsString('E_USER_ERROR', $source);
    }

    private function source($path)
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/' . $path);
        $this->assertIsString($source);

        return $source;
    }
}
