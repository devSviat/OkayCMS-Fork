<?php

namespace Security;

use PHPUnit\Framework\TestCase;

/**
 * Ajax-скрипти адмінки, що змінюють дані, перевіряють токен сесії, як і форми.
 * Кука сесії має SameSite=Lax і з чужого сайту на GET усе одно приходить, тож
 * зміна на GET без токена відкривалась простим посиланням.
 */
class AdminAjaxSessionTest extends TestCase
{
    public function testLessonIsMarkedDoneOnlyByAPostWithTheToken(): void
    {
        $script = $this->source('backend/ajax/lesson_done.php');

        $this->assertStringContainsString("\$request->method('post')", $script);
        $this->assertStringContainsString('$request->checkSession()', $script);
        $this->assertStringNotContainsString("\$request->get('lesson')", $script);

        $template = $this->source('backend/design/html/learning_hints.tpl');
        $calls = substr_count($template, "url: 'ajax/lesson_done.php'");
        $this->assertGreaterThan(0, $calls);
        $this->assertSame($calls, substr_count($template, "type: 'POST'"));
        $this->assertSame($calls, substr_count($template, 'session_id: '));
    }

    public function testOrderLabelsCheckTheToken(): void
    {
        $script = $this->source('backend/ajax/update_order.php');

        $guard = strpos($script, '$request->checkSession()');
        $this->assertIsInt($guard);
        $this->assertLessThan(strpos($script, '$orderLabelsEntity->addOrderLabels('), $guard);
    }

    /** Міграції виконують SQL з теки модуля, тож лише POST, який перевіряється на токен у backend/index.php. */
    public function testAutoDeployMigrationsRunOnlyOnPost(): void
    {
        $controller = $this->source('Okay/Modules/OkayCMS/AutoDeploy/Backend/Controllers/AutoDeployAdmin.php');
        $method = substr($controller, strpos($controller, 'public function executeMigrations'));
        $method = substr($method, 0, strpos($method, '$deployHelper->executeMigrations()'));
        $this->assertStringContainsString("\$this->request->method('post')", $method);

        $template = $this->source('Okay/Modules/OkayCMS/AutoDeploy/Backend/design/html/auto_deploy.tpl');
        $call = substr($template, strpos($template, "AutoDeployAdmin@executeMigrations") - 120, 400);
        $this->assertStringContainsString("type: 'post'", $call);
        $this->assertStringContainsString('session_id:', $call);
    }

    private function source(string $path): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/' . $path);
    }
}
