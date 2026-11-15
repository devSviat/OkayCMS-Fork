<?php

namespace Core\Modules;

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
 * Встановлення модуля, якого ще немає в ok_modules: його не запущено, тож
 * сервіси й параметри модуля в контейнері відсутні. Installer має зареєструвати
 * їх сам до install() і update_*, а на збої прибрати щойно доданий рядок.
 */
#[AllowMockObjectsWithoutExpectations]
class InstallerModuleLifecycleTest extends TestCase
{
    private const int MODULE_ID = 7;

    protected function setUp(): void
    {
        LifecycleInit::$log = [];
        LifecycleInit::$failOn = null;
    }

    public function testUpdateMethodResolvesModuleOwnServiceOnFreshInstall(): void
    {
        $installer = $this->installer($deleted);

        $this->assertSame(self::MODULE_ID, $installer->install('Vendor/Lifecycle'));
        $this->assertSame(['install', 'update_1_0_1'], LifecycleInit::$log);
        $this->assertInstanceOf(LifecycleService::class, LifecycleInit::$resolved);
        $this->assertSame('from-parameters', LifecycleInit::$parameter);
        $this->assertSame([], $deleted, 'успішне встановлення рядок не чіпає');
    }

    public function testFailingUpdateMethodRemovesModuleRow(): void
    {
        LifecycleInit::$failOn = 'update_1_0_1';
        $installer = $this->installer($deleted);

        try {
            $installer->install('Vendor/Lifecycle');
            $this->fail('виняток з update_* мусить дійти до адмінки');
        } catch (\RuntimeException $e) {
            $this->assertSame('update_1_0_1 failed', $e->getMessage());
        }

        $this->assertSame([[self::MODULE_ID]], $deleted);
    }

    public function testFailingInstallRemovesModuleRowAndSkipsUpdates(): void
    {
        LifecycleInit::$failOn = 'install';
        $installer = $this->installer($deleted);

        try {
            $installer->install('Vendor/Lifecycle');
            $this->fail('виняток з install() мусить дійти до адмінки');
        } catch (\RuntimeException $e) {
            $this->assertSame('install failed', $e->getMessage());
        }

        $this->assertSame([[self::MODULE_ID]], $deleted);
        $this->assertSame(['install'], LifecycleInit::$log);
    }

    private function installer(&$deleted): Installer
    {
        $deleted = [];

        $modulesEntity = $this->createStub(ModulesEntity::class);
        $modulesEntity->method('cols')->willReturnSelf();
        $modulesEntity->method('find')->willReturn([]);
        $modulesEntity->method('add')->willReturn(self::MODULE_ID);
        $modulesEntity->method('delete')->willReturnCallback(
            function ($ids) use (&$deleted) {
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
        $moduleCore->method('getModuleDirectory')->willReturn('/modules/Vendor/Lifecycle/');
        $moduleCore->method('getModuleParams')->willReturn($params);
        $moduleCore->method('getInitClassName')->willReturn(LifecycleInit::class);
        $moduleCore->method('getServices')->willReturn([
            LifecycleService::class => ['class' => LifecycleService::class],
        ]);
        $moduleCore->method('getParameters')->willReturn([
            'lifecycle_test' => ['value' => 'from-parameters'],
        ]);

        // Справжній Modules: перевіряється саме реєстрація в контейнері, а не виклик методу.
        $modules = (new ReflectionClass(Modules::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(Modules::class, 'module'))->setValue($modules, $moduleCore);

        return new Installer($entityFactory, $moduleCore, $modules);
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
