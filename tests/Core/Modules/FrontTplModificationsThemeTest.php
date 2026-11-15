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
    // Справжні теми з design/: FrontTemplateConfig::getTheme() перевіряє тему перегляду саме там.
    private const string ACTIVE_THEME = 'okay_shop';
    private const string PREVIEW_THEME = 'vibe_shop';

    private string $fixtureDir;
    private string $designDir;
    private int $paramsReads = 0;

    protected function setUp(): void
    {
        $this->fixtureDir = sys_get_temp_dir() . '/tplmods-theme-' . bin2hex(random_bytes(6));
        $this->designDir = $this->fixtureDir . '/design/';

        foreach ([self::ACTIVE_THEME, self::PREVIEW_THEME] as $theme) {
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
            [['first from okay_shop', 'Test/First'], ['second from okay_shop', 'Test/Second'], ['plain <b>', 'Test/Second']],
            $this->summary($storefront->getFrontModulesTplModifications())
        );

        $_COOKIE['admin_login'] = 'admin';
        $admin = $this->modules();
        $this->assertSame(
            [['first from vibe_shop', 'Test/First'], ['second from vibe_shop', 'Test/Second'], ['plain <b>', 'Test/Second']],
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

        $this->assertSame('first from vibe_shop', $this->firstValue($modules->getFrontModulesTplModifications()));
        $this->assertSame('first from okay_shop', $this->firstValue($modules->getFrontModulesTplModifications(self::ACTIVE_THEME)));
        $this->assertSame('first from vibe_shop', $this->firstValue($modules->getFrontModulesTplModifications()));
    }

    public function testExplicitThemeBeforeNullDoesNotLeakIntoCurrentRequest(): void
    {
        $_COOKIE['admin_login'] = 'admin';
        $modules = $this->modules();

        $this->assertSame(
            [['first from okay_shop', 'Test/First'], ['second from okay_shop', 'Test/Second'], ['plain <b>', 'Test/Second']],
            $this->summary($modules->getFrontModulesTplModifications(self::ACTIVE_THEME))
        );
        $this->assertSame('first from vibe_shop', $this->firstValue($modules->getFrontModulesTplModifications()));
    }

    public function testCacheIsKeptPerTheme(): void
    {
        $modules = $this->modules();
        $startupReads = $this->paramsReads;

        $active = $modules->getFrontModulesTplModifications(self::ACTIVE_THEME);
        $preview = $modules->getFrontModulesTplModifications(self::PREVIEW_THEME);

        $this->assertSame('first from okay_shop', $this->firstValue($active));
        $this->assertSame('first from vibe_shop', $this->firstValue($preview));
        $this->assertSame($active, $modules->getFrontModulesTplModifications(self::ACTIVE_THEME));
        $this->assertSame($preview, $modules->getFrontModulesTplModifications(self::PREVIEW_THEME));
        $this->assertSame(4, $this->paramsReads - $startupReads, 'кожна тема читає module.json один раз на модуль');
    }

    public static function rejectedThemes(): array
    {
        return [
            'parent' => ['..'],
            'current' => ['.'],
            'escape to sibling' => ['../outside'],
            'escape via theme' => ['okay_shop/../../outside'],
            'trailing slash' => ['okay_shop/'],
            'absolute' => ['/tmp'],
            'backslash' => ['okay_shop\\..'],
            'nul byte' => ["okay_shop\0"],
            'trailing newline' => ["okay_shop\n"],
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
        $this->setPrivate($config, 'adminTheme', self::PREVIEW_THEME);
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
