<?php

namespace Core\Modules;

use Okay\Core\Config;
use Okay\Core\Database;
use Okay\Core\EntityFactory;
use Okay\Core\Modules\DTO\ModificationDTO;
use Okay\Core\Modules\DTO\ModuleParamsDTO;
use Okay\Core\Modules\DTO\TplChangeDTO;
use Okay\Core\Modules\Module;
use Okay\Core\Modules\Modules;
use Okay\Core\QueryFactory;
use Okay\Core\TemplateConfig\FrontTemplateConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Smarty\Smarty;

/**
 * Модифікація, значення якої - ім'я файлу, вставляє вміст цього файлу з теми.
 * В адмінці з cookie admin_login FrontTemplateConfig::getTheme() повертає тему
 * попереднього перегляду, тому getFrontModulesTplModifications($theme) бере файли
 * з указаної теми.
 */
class FrontTplModificationsThemeTest extends TestCase
{
    private const string ACTIVE_THEME = 'storefront';

    // FrontTemplateConfig::getTheme() вимикає тему перегляду, якої немає в справжньому design/,
    // тож вона мусить бути наявною темою репозиторію.
    private string $previewTheme;
    private string $fixtureDir;
    private string $designDir;
    private int $paramsReads = 0;

    protected function setUp(): void
    {
        $realThemes = glob(dirname(__DIR__, 3) . '/design/*/html', GLOB_ONLYDIR) ?: [];
        if ($realThemes === []) {
            $this->markTestSkipped('у design/ немає жодної теми');
        }
        $this->previewTheme = basename(dirname($realThemes[0]));

        $this->fixtureDir = sys_get_temp_dir() . '/tplmods-theme-' . bin2hex(random_bytes(6));
        $this->designDir = $this->fixtureDir . '/design/';

        foreach ([self::ACTIVE_THEME, $this->previewTheme] as $theme) {
            $this->writeModuleFile($this->designDir . $theme, 'Test/First', "first from {$theme}");
            $this->writeModuleFile($this->designDir . $theme, 'Test/Second', "second from {$theme}");
        }
        $this->writeModuleFile($this->fixtureDir . '/outside', 'Test/First', 'outside design');
        mkdir($this->designDir . 'with.dot');
    }

    protected function tearDown(): void
    {
        unset($_COOKIE['admin_login']);
        $this->removeDir($this->fixtureDir);
    }

    public function testNullResolvesAgainstThemeOfCurrentRequest(): void
    {
        $storefront = $this->modules();
        $this->assertSame(
            [[$this->active('first'), 'Test/First'], [$this->active('second'), 'Test/Second'], ['plain <b>', 'Test/Second']],
            $this->summary($storefront->getFrontModulesTplModifications())
        );

        $_COOKIE['admin_login'] = 'admin';
        $admin = $this->modules();
        $this->assertSame(
            [[$this->preview('first'), 'Test/First'], [$this->preview('second'), 'Test/Second'], ['plain <b>', 'Test/Second']],
            $this->summary($admin->getFrontModulesTplModifications())
        );
    }

    public function testNullKeepsResolvingTheStartupDtosInPlace(): void
    {
        $modules = $this->modules();
        $params = new \ReflectionProperty(Modules::class, 'modulesParams')->getValue($modules);
        $startupReads = $this->paramsReads;

        $modifications = $modules->getFrontModulesTplModifications();

        $this->assertSame($params['Test/First']->getFrontModifications()[0], $modifications[0]);
        $this->assertSame($modifications, $modules->getFrontModulesTplModifications());
        $this->assertSame($startupReads, $this->paramsReads, 'шлях без теми не має перечитувати module.json');
    }

    public function testExplicitThemeIgnoresPreviewThemeOfAdminRequest(): void
    {
        $_COOKIE['admin_login'] = 'admin';
        $modules = $this->modules();

        $this->assertSame($this->preview('first'), $this->firstValue($modules->getFrontModulesTplModifications()));
        $this->assertSame($this->active('first'), $this->firstValue($modules->getFrontModulesTplModifications(self::ACTIVE_THEME)));
        $this->assertSame($this->preview('first'), $this->firstValue($modules->getFrontModulesTplModifications()));
    }

    public function testExplicitThemeBeforeNullDoesNotLeakIntoCurrentRequest(): void
    {
        $_COOKIE['admin_login'] = 'admin';
        $modules = $this->modules();

        $this->assertSame(
            [[$this->active('first'), 'Test/First'], [$this->active('second'), 'Test/Second'], ['plain <b>', 'Test/Second']],
            $this->summary($modules->getFrontModulesTplModifications(self::ACTIVE_THEME))
        );
        $this->assertSame($this->preview('first'), $this->firstValue($modules->getFrontModulesTplModifications()));
    }

    public function testCacheIsKeptPerTheme(): void
    {
        $modules = $this->modules();
        $startupReads = $this->paramsReads;

        $active = $modules->getFrontModulesTplModifications(self::ACTIVE_THEME);
        $preview = $modules->getFrontModulesTplModifications($this->previewTheme);

        $this->assertSame($this->active('first'), $this->firstValue($active));
        $this->assertSame($this->preview('first'), $this->firstValue($preview));
        $this->assertSame($active, $modules->getFrontModulesTplModifications(self::ACTIVE_THEME));
        $this->assertSame($preview, $modules->getFrontModulesTplModifications($this->previewTheme));
        $this->assertSame(4, $this->paramsReads - $startupReads, 'кожна тема читає module.json один раз на модуль');
    }

    public static function rejectedThemes(): array
    {
        return [
            'parent' => ['..'],
            'current' => ['.'],
            'escape to sibling' => ['../outside'],
            'escape via theme' => ['storefront/../../outside'],
            'trailing slash' => ['storefront/'],
            'absolute' => ['/tmp'],
            'backslash' => ['storefront\\..'],
            'nul byte' => ["storefront\0"],
            'trailing newline' => ["storefront\n"],
            'empty' => [''],
            'dot in existing dir' => ['with.dot'],
            'missing theme' => ['no_such_theme'],
        ];
    }

    #[DataProvider('rejectedThemes')]
    public function testThemeOutsideDesignIsRejected(string $theme): void
    {
        $modules = $this->modules();
        $readsBefore = $this->paramsReads;

        try {
            $modules->getFrontModulesTplModifications($theme);
            $this->fail('тему прийнято: ' . json_encode($theme));
        } catch (\InvalidArgumentException) {
        }

        $this->assertSame($readsBefore, $this->paramsReads);
    }

    private function modules(): Modules
    {
        $module = $this->createStub(Module::class);
        $module->method('getModuleParams')->willReturnCallback(function (string $vendor, string $moduleName): ModuleParamsDTO {
            $this->paramsReads++;
            return $this->moduleParams($moduleName);
        });

        // Деструктор Database кличе $this->pdo->disconnect(), а pdo у заглушки null.
        $database = $this->createStub(Database::class);
        new \ReflectionProperty(Database::class, 'pdo')->setValue($database, new class {
            public function disconnect(): void {}
        });

        $modules = new Modules(
            $this->createStub(EntityFactory::class),
            $module,
            $this->createStub(QueryFactory::class),
            $database,
            $this->createStub(Config::class),
            $this->createStub(Smarty::class)
        );

        $running = [];
        $params = [];
        // Порядок як після startModules(): resolve обходить модулі у зворотному порядку.
        foreach (['Second', 'First'] as $name) {
            $running['Test/' . $name] = ['vendor' => 'Test', 'module_name' => $name, 'is_active' => 1];
            $params['Test/' . $name] = $module->getModuleParams('Test', $name);
        }
        $running['Test/Disabled'] = ['vendor' => 'Test', 'module_name' => 'Disabled', 'is_active' => 0];
        $params['Test/Disabled'] = $module->getModuleParams('Test', 'Disabled');

        $this->setPrivate($modules, 'runningModules', $running);
        $this->setPrivate($modules, 'modulesParams', $params);
        $this->setPrivate($modules, 'designDir', $this->designDir);
        $this->setPrivate($modules, 'frontTemplateConfig', $this->frontTemplateConfig());

        return $modules;
    }

    private function moduleParams(string $moduleName): ModuleParamsDTO
    {
        $dto = new ModuleParamsDTO();
        $changes = [new TplChangeDTO('', 'anchor')->setAppend('insert.tpl')];
        if ($moduleName === 'Second') {
            $changes[] = new TplChangeDTO('', 'anchor')->setAppend('plain <b>');
        }
        if ($moduleName === 'Disabled') {
            $changes = [new TplChangeDTO('', 'anchor')->setAppend('disabled')];
        }
        $dto->setFrontModification(new ModificationDTO('index.tpl', $changes));

        return $dto;
    }

    private function frontTemplateConfig(): FrontTemplateConfig
    {
        $config = new \ReflectionClass(FrontTemplateConfig::class)->newInstanceWithoutConstructor();
        $this->setPrivate($config, 'theme', self::ACTIVE_THEME);
        $this->setPrivate($config, 'adminTheme', $this->previewTheme);
        $this->setPrivate($config, 'adminThemeManagers', []);
        // Деструктор чистить застарілі css/js у rootDir; хай дивиться в порожній каталог фікстури.
        $this->setPrivate($config, 'rootDir', $this->fixtureDir . '/');
        $this->setPrivate($config, 'compileCssDir', 'compiled/');
        $this->setPrivate($config, 'compileJsDir', 'compiled/');

        return $config;
    }

    /**
     * @param ModificationDTO[] $modifications
     * @return list<array{string, string}>
     */
    private function summary(array $modifications): array
    {
        $summary = [];
        foreach ($modifications as $modification) {
            foreach ($modification->getChanges() as $change) {
                $summary[] = [$change->getAppend(), $change->getComment()];
            }
        }

        return $summary;
    }

    /** @param ModificationDTO[] $modifications */
    private function firstValue(array $modifications): string
    {
        return $modifications[0]->getChanges()[0]->getAppend();
    }

    private function active(string $module): string
    {
        return $module . ' from ' . self::ACTIVE_THEME;
    }

    private function preview(string $module): string
    {
        return $module . ' from ' . $this->previewTheme;
    }

    private function writeModuleFile(string $themeDir, string $vendorModule, string $content): void
    {
        $dir = $themeDir . '/modules/' . $vendorModule . '/html';
        mkdir($dir, 0777, true);
        file_put_contents($dir . '/insert.tpl', $content);
    }

    private function setPrivate(object $object, string $property, mixed $value): void
    {
        new \ReflectionProperty($object, $property)->setValue($object, $value);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }
}
