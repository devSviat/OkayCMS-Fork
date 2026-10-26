<?php

namespace Core;

use Okay\Core\ManagerMenu;
use Okay\Core\Managers;
use Okay\Core\Modules\Module;
use PHPUnit\Framework\TestCase;

/**
 * Блок «Модулі» у формі прав менеджера: кожен перемикач має бути впізнаваним.
 * Модуль з кількома правами раніше давав кілька однакових рядків «Vendor/Module».
 */
class ManagerMenuModulePermissionTitlesTest extends TestCase
{
    public function testSeveralPermissionsOfOneModuleGetMenuItemTitles(): void
    {
        $managers = new Managers();
        $managers->addModulePermission('vendor__calls', 'Vendor/Phone');
        $managers->addModuleControllerPermission('Vendor.Phone.CallsAdmin', 'vendor__calls');
        $managers->addModulePermission('vendor__queue', 'Vendor/Phone');
        $managers->addModuleControllerPermission('Vendor.Phone.QueueAdmin', 'vendor__queue');
        $managers->addModulePermission('vendor__unlisted', 'Vendor/Phone');
        $managers->addModulePermission('vendor__single', 'Vendor/Single');

        $menu = new ManagerMenu($managers, $this->createStub(Module::class));
        $menu->extendMenu('vendor__left_phone', [
            'vendor__left_phone_calls' => ['Vendor.Phone.CallsAdmin'],
            'vendor__left_phone_queue' => ['Vendor.Phone.QueueAdmin'],
        ], '');

        $btr = new class {
            public function __get($key)
            {
                return [
                    'vendor__left_phone_calls' => 'Calls log',
                    'vendor__left_phone_queue' => 'Call back',
                ][$key] ?? $key;
            }
        };
        $manager = (object) ['permissions' => ['vendor__calls', 'vendor__queue', 'vendor__unlisted', 'vendor__single']];

        $modules = $menu->getPermissionMenu($manager, $btr)['left_modules'];

        $this->assertSame([
            'vendor__calls' => 'Vendor/Phone — Calls log',
            'vendor__queue' => 'Vendor/Phone — Call back',
            'vendor__unlisted' => 'Vendor/Phone — vendor__unlisted',
            'vendor__single' => 'Vendor/Single',
        ], $modules);
    }
}
