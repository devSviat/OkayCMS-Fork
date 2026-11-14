<?php

namespace Admin;

use Okay\Core\Config;
use Okay\Core\Database;
use Okay\Core\EntityFactory;
use Okay\Core\Modules\Module;
use Okay\Core\Modules\Modules;
use Okay\Core\QueryFactory;
use Okay\Entities\ModulesEntity;
use PHPUnit\Framework\TestCase;
use Smarty\Smarty;

/**
 * Сторінка способу оплати друкувала кожне налаштування в value текстового
 * поля, тож приватні ключі платіжних модулів лежали в HTML адмінки. Тип
 * secret у settings.xml прибирає значення зі сторінки зовсім.
 */
class PaymentSecretSettingTemplateTest extends TestCase
{
    private const string TEMPLATE = 'backend/design/html/payment_method.tpl';
    private const string STORED_SECRET = 'Sk-Fake-7f3a"<b>';
    private const string FIXTURE_DIR = __DIR__ . '/Fixtures/SecretSettingsModule/';

    private ?string $compileDir = null;

    protected function tearDown(): void
    {
        if ($this->compileDir !== null && is_dir($this->compileDir)) {
            array_map('unlink', glob($this->compileDir . '/*') ?: []);
            rmdir($this->compileDir);
        }
    }

    public function testSecretBranchNeverPrintsStoredValue(): void
    {
        $branch = $this->secretBranchSource();

        $this->assertStringContainsString('type="password"', $branch);
        $this->assertStringContainsString('autocomplete="new-password"', $branch);
        $this->assertStringContainsString('value=""', $branch);
        $this->assertStringNotContainsString('{$payment_method->payment_settings', $branch);

        $html = $this->render(['vendor__private_key' => self::STORED_SECRET, 'vendor__public_key' => 'public-0000']);

        $this->assertStringNotContainsString('Sk-Fake', $html);
        $this->assertMatchesRegularExpression(
            '~<input name="payment_settings\[vendor__private_key\]"[^>]*type="password" autocomplete="new-password" value=""[^>]*placeholder="Збережено ••••"~u',
            $html
        );
        $this->assertStringContainsString('name="payment_settings_clear[vendor__private_key]"', $html);
        $this->assertStringContainsString('Стерти', $html);
        $this->assertStringContainsString('value="public-0000"', $html, 'звичайне поле має друкуватися як раніше');
    }

    public function testEmptySecretHasNoSavedMarkAndNoClearBox(): void
    {
        $html = $this->render(['vendor__public_key' => 'public-0000']);

        $this->assertStringContainsString('name="payment_settings[vendor__private_key]"', $html);
        $this->assertStringNotContainsString('Збережено', $html);
        $this->assertStringNotContainsString('payment_settings_clear', $html);
    }

    public function testModulesAcceptsSecretType(): void
    {
        $settings = $this->modules()->getPaymentModules('ua')['Test/SecretSettings']->settings;

        $this->assertSame('text', $settings['vendor__public_key']->type);
        $this->assertSame('secret', $settings['vendor__private_key']->type);
        $this->assertSame('secret', $settings['vendor__token']->type);
        $this->assertSame('text', $settings['vendor__unknown_type']->type, 'невідомий тип і далі стає text');
    }

    /** Сторінка доставки секретних полів не вміє, тож там тип лишається text, як і був. */
    public function testDeliveryModulesKeepSecretAsText(): void
    {
        $settings = $this->modules()->getDeliveryModules('ua')['Test/SecretSettings']->settings;

        $this->assertSame('text', $settings['vendor__private_key']->type);
    }

    public function testSecretPaymentSettingVariables(): void
    {
        $this->assertSame(['vendor__private_key', 'vendor__token'], $this->modules()->secretPaymentSettingVariables());
    }

    private function modules(): Modules
    {
        $modulesEntity = $this->createStub(ModulesEntity::class);
        $modulesEntity->method('find')->willReturn([
            (object)['vendor' => 'Test', 'module_name' => 'SecretSettings'],
        ]);

        $entityFactory = $this->createStub(EntityFactory::class);
        $entityFactory->method('get')->willReturn($modulesEntity);

        $module = $this->createStub(Module::class);
        $module->method('getModuleDirectory')->willReturn(self::FIXTURE_DIR);

        // Деструктор Database кличе $this->pdo->disconnect(), а pdo у заглушки null.
        $database = $this->createStub(Database::class);
        new \ReflectionProperty(Database::class, 'pdo')->setValue($database, new class {
            public function disconnect(): void {}
        });

        return new Modules(
            $entityFactory,
            $module,
            $this->createStub(QueryFactory::class),
            $database,
            $this->createStub(Config::class),
            $this->createStub(Smarty::class)
        );
    }

    private function secretBranchSource(): string
    {
        $source = $this->read(self::TEMPLATE);
        $start = strpos($source, "{elseif isset(\$setting->type) && \$setting->type == 'secret'}");
        $this->assertIsInt($start, 'у шаблоні немає гілки secret');
        $end = strpos($source, '{else}', $start);
        $this->assertIsInt($end);

        return substr($source, $start, $end - $start);
    }

    /**
     * Рендеримо справжній блок налаштувань із шаблону, а не копію: так тест
     * бачить і гілку secret, і звичайне текстове поле поруч.
     */
    private function render(array $storedSettings): string
    {
        $source = $this->read(self::TEMPLATE);
        $open = '{foreach $payment_modules as $payment_module}';
        $settingsBlock = strpos($source, 'fn_module_settings');
        $this->assertIsInt($settingsBlock);
        $start = strrpos(substr($source, 0, $settingsBlock), $open);
        $this->assertIsInt($start);

        $depth = 0;
        $offset = $start;
        while (preg_match('~\{(/?)foreach\b~', $source, $match, PREG_OFFSET_CAPTURE, $offset)) {
            $depth += $match[1][0] === '/' ? -1 : 1;
            $offset = $match[0][1] + strlen($match[0][0]);
            if ($depth === 0) {
                break;
            }
        }
        $block = substr($source, $start, strpos($source, '}', $offset) + 1 - $start);

        $this->compileDir = sys_get_temp_dir() . '/okay-secret-tpl-' . bin2hex(random_bytes(4));
        mkdir($this->compileDir);

        $smarty = new Smarty();
        $smarty->setCompileDir($this->compileDir);
        $smarty->registerPlugin('modifier', 'first', static fn ($value) => is_array($value) ? reset($value) : false);

        $settings = [];
        foreach (['vendor__public_key' => 'text', 'vendor__private_key' => 'secret'] as $variable => $type) {
            $settings[$variable] = (object)['name' => $variable, 'variable' => $variable, 'type' => $type];
        }

        $smarty->assign('payment_modules', ['Test/SecretSettings' => (object)[
            'vendor' => 'Test',
            'module_name' => 'SecretSettings',
            'settings' => $settings,
        ]]);
        $smarty->assign('payment_method', (object)['module' => 'Test/SecretSettings', 'payment_settings' => $storedSettings]);
        $smarty->assign('btr', (object)$this->backendTranslations());

        return $smarty->fetch('string:' . $block);
    }

    /** @return array<string, string> */
    private function backendTranslations(): array
    {
        $lang = [];
        include dirname(__DIR__, 2) . '/backend/lang/ua.php';

        return $lang;
    }

    private function read(string $path): string
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/' . $path);
        $this->assertIsString($source);

        return $source;
    }
}
