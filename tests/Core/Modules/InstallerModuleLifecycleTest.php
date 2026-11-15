<?php

namespace Core\Modules;

use Okay\Core\Config;
use Okay\Core\EntityFactory;
use Okay\Core\Modules\DTO\ModuleParamsDTO;
use Okay\Core\Modules\Installer;
use Okay\Core\Modules\Module;
use Okay\Core\Modules\Modules;
use Okay\Core\OkayContainer\OkayContainer;
use Okay\Entities\ModulesEntity;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;

/**
 * Модуль, якого ще немає в ok_modules або який вимкнено, на старті запиту не
 * запущено, тож його сервіси, параметри й config.php у ядро не потрапили.
 * Installer має підтягнути їх сам до install() і update_*, а на збої
 * встановлення прибрати щойно доданий рядок.
 */
#[AllowMockObjectsWithoutExpectations]
class InstallerModuleLifecycleTest extends TestCase
{
    private const int MODULE_ID = 7;

    private string $moduleDir;
    private array $loadedConfigs = [];

    protected function setUp(): void
    {
        LifecycleInit::reset();
        $this->forgetModuleInContainer();
        $this->assertFalse(OkayContainer::getInstance()->has(LifecycleService::class), 'контейнер мусить стартувати без сервісу модуля');

        $this->moduleDir = sys_get_temp_dir() . '/okay-lifecycle-' . bin2hex(random_bytes(4)) . '/';
        mkdir($this->moduleDir . 'config', 0777, true);
        file_put_contents($this->moduleDir . 'config/config.php', "lifecycle_flag = 1\n");
        $this->loadedConfigs = [];
    }

    protected function tearDown(): void
    {
        $this->forgetModuleInContainer();
        @unlink($this->moduleDir . 'config/config.php');
        @rmdir($this->moduleDir . 'config');
        @rmdir($this->moduleDir);
    }

    public function testFreshInstallBindsModuleBeforeInstallAndUpdates(): void
    {
        $installer = $this->installer($deleted);

        $this->assertSame(self::MODULE_ID, $installer->install('Vendor/Lifecycle'));
        $this->assertSame(['install', 'update_1_0_1'], LifecycleInit::$log);
        $this->assertInstanceOf(LifecycleService::class, LifecycleInit::$resolved);
        $this->assertSame('from-parameters', LifecycleInit::$parameter);
        $this->assertSame([$this->moduleDir . 'config/config.php'], $this->loadedConfigs);
        $this->assertSame([], $deleted, 'успішне встановлення рядок не чіпає');
    }

    public function testUpdateOfModuleThatWasNotStartedBindsItsServices(): void
    {
        $installer = $this->installer($deleted, $updated);

        $installer->update(self::MODULE_ID);

        $this->assertSame(['update_1_0_1'], LifecycleInit::$log);
        $this->assertInstanceOf(LifecycleService::class, LifecycleInit::$resolved);
        $this->assertSame([self::MODULE_ID, ['version' => '1.0.1']], $updated);
    }

    public function testFailingUpdateMethodRemovesModuleRow(): void
    {
        LifecycleInit::$failOn = 'update_1_0_1';
        $installer = $this->installer($deleted);

        $this->assertInstallThrows($installer, 'update_1_0_1 failed');
        $this->assertSame([[self::MODULE_ID]], $deleted);
    }

    public function testFailingInstallRemovesModuleRowAndSkipsUpdates(): void
    {
        LifecycleInit::$failOn = 'install';
        $installer = $this->installer($deleted);

        $this->assertInstallThrows($installer, 'install failed');
        $this->assertSame([[self::MODULE_ID]], $deleted);
        $this->assertSame(['install'], LifecycleInit::$log);
    }

    public function testFailingRowRemovalKeepsOriginalException(): void
    {
        LifecycleInit::$failOn = 'install';
        $installer = $this->installer($deleted, $updated, deleteThrows: true);

        $this->assertInstallThrows($installer, 'install failed');
    }

    private function assertInstallThrows(Installer $installer, string $message): void
    {
        try {
            $installer->install('Vendor/Lifecycle');
            $this->fail('виняток мусить дійти до адмінки');
        } catch (\RuntimeException $e) {
            $this->assertSame($message, $e->getMessage());
        }
    }

    private function installer(&$deleted, &$updated = null, bool $deleteThrows = false): Installer
    {
        $deleted = [];
        $updated = null;

        $modulesEntity = $this->createStub(ModulesEntity::class);
        $modulesEntity->method('cols')->willReturnSelf();
        $modulesEntity->method('find')->willReturn([]);
        $modulesEntity->method('add')->willReturn(self::MODULE_ID);
        $modulesEntity->method('findOne')->willReturn((object)[
            'id' => self::MODULE_ID,
            'vendor' => 'Vendor',
            'module_name' => 'Lifecycle',
            'version' => '1.0.0',
            'enabled' => 0,
        ]);
        $modulesEntity->method('update')->willReturnCallback(
            function ($id, $object) use (&$updated) {
                $updated = [$id, $object];
                return true;
            }
        );
        $modulesEntity->method('delete')->willReturnCallback(
            function ($ids) use (&$deleted, $deleteThrows) {
                if ($deleteThrows) {
                    throw new \LogicException('delete failed');
                }
                $deleted[] = $ids;
                return true;
            }
        );

        $entityFactory = $this->createStub(EntityFactory::class);
        $entityFactory->method('get')->willReturn($modulesEntity);

        $params = new ModuleParamsDTO();
        $params->fromArray(['version' => '1.0.1', 'math_version' => 101100101]);

        $moduleCore = $this->getMockBuilder(Module::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getModuleDirectory', 'getModuleParams', 'getInitClassName', 'getServices', 'getParameters'])
            ->getMock();
        $moduleCore->method('getModuleDirectory')->willReturn($this->moduleDir);
        $moduleCore->method('getModuleParams')->willReturn($params);
        $moduleCore->method('getInitClassName')->willReturn(LifecycleInit::class);
        $moduleCore->method('getServices')->willReturn([
            LifecycleService::class => ['class' => LifecycleService::class],
        ]);
        $moduleCore->method('getParameters')->willReturn([
            'lifecycle_test' => ['value' => 'from-parameters'],
        ]);

        $config = $this->createStub(Config::class);
        $config->method('loadConfigsFrom')->willReturnCallback(
            function ($file) {
                $this->loadedConfigs[] = $file;
            }
        );

        // Справжній Modules: перевіряється саме реєстрація в контейнері, а не виклик методу.
        $modules = (new ReflectionClass(Modules::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(Modules::class, 'module'))->setValue($modules, $moduleCore);
        (new ReflectionProperty(Modules::class, 'config'))->setValue($modules, $config);

        return new Installer($entityFactory, $moduleCore, $modules);
    }

    /**
     * Контейнер — процесний синглтон без API для зняття реєстрації. Без чистки
     * сервіс із попереднього тесту зробив би контроль «без bindModuleServices»
     * залежним від порядку тестів.
     */
    private function forgetModuleInContainer(): void
    {
        $container = OkayContainer::getInstance();
        foreach (['services' => LifecycleService::class, 'serviceStore' => LifecycleService::class, 'parameters' => 'lifecycle_test'] as $property => $key) {
            $reflection = new ReflectionProperty(OkayContainer::class, $property);
            $values = $reflection->getValue($container);
            unset($values[$key]);
            $reflection->setValue($container, $values);
        }
    }
}

class LifecycleService
{
}

/**
 * Init модуля, чий update_* бере власний сервіс через контейнер, як це робить
 * міграція справжнього модуля.
 */
class LifecycleInit
{
    public static array $log = [];
    public static ?string $failOn = null;
    public static ?object $resolved = null;
    public static mixed $parameter = null;

    public static function reset(): void
    {
        self::$log = [];
        self::$failOn = null;
        self::$resolved = null;
        self::$parameter = null;
    }

    public function __construct($moduleId, $vendor, $moduleName)
    {
    }

    public function install(): void
    {
        $this->step('install');
    }

    public function update_1_0_1(): void
    {
        $this->step('update_1_0_1');
        $container = OkayContainer::getInstance();
        self::$resolved = $container->get(LifecycleService::class);
        self::$parameter = $container->getParameter('lifecycle_test.value');
    }

    private function step(string $name): void
    {
        self::$log[] = $name;
        if (self::$failOn === $name) {
            throw new \RuntimeException($name . ' failed');
        }
    }
}
