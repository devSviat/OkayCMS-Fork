<?php


namespace Okay\Admin\Controllers;


use Okay\Core\Managers;
use Okay\Core\Modules\Installer;
use Okay\Core\Modules\ModuleVersionControl;
use Okay\Entities\ManagersEntity;
use Okay\Entities\ModulesEntity;
use Okay\Core\Modules\Module;

class ModulesAdmin extends IndexAdmin
{
    public function fetch(
        ModulesEntity  $modulesEntity,
        Installer      $modulesInstaller,
        Module         $moduleCore,
        ManagersEntity $managersEntity,
        Managers       $managersCore
    ) {
        // Обработка действий
        if ($this->request->method('post')) {
            if (!empty($this->request->post('install_module'))) {
                // Installer::install() кидає виняток, зокрема на вже встановленому
                // модулі. Без перехоплення адмінка віддавала голий Fatal error.
                try {
                    $installed = $modulesInstaller->install($this->request->post('install_module'));
                } catch (\Exception $e) {
                    $installed = false;
                    $this->design->assign('message_error', $e->getMessage());
                }

                if ($installed) {
                    $this->design->clearCompiled();
                    $this->response->redirectTo($this->request->getCurrentUrl());
                }
            }

            // Действия с выбранными
            $ids = $this->request->post('check');
            if (is_array($ids)) {
                switch ($this->request->post('action')) {
                    case 'disable': {
                        $modulesEntity->disable($ids);
                        $this->design->clearCompiled();
                        break;
                    }
                    case 'enable': {
                        $modulesEntity->enable($ids);
                        $this->design->clearCompiled();
                        break;
                    }
                    case 'delete': {
                        $modulesEntity->delete($ids);
                        $this->design->clearCompiled();
                        $this->response->redirectTo($this->request->getCurrentUrl());
                        break;
                    }
                    case 'update': {
                        foreach ($ids as $id) {
                            $modulesInstaller->update((int)$id);
                        }
                        $this->design->clearCompiled();
                        break;
                    }
                }
            }

            // Сортировка
            $positions = (array)$this->request->post('positions');
            $ids = array_keys($positions);
            rsort($positions);
            foreach ($positions as $i=>$position) {
                $modulesEntity->update($ids[$i], ['position'=>$position]);
            }

            $this->response->redirectTo($this->request->getCurrentUrl());
        }

        $filter = [];
        $manager = $managersEntity->findOne(['login' => $_SESSION['admin']]);
        if ($managersCore->cannotVisibleSystemModules($manager)) {
            $filter['without_system'] = 1;
        }

        $modulesList = array_merge($modulesEntity->findNotInstalled(), $modulesEntity->find($filter));

        foreach ($modulesList as $module) {
            $preview = $moduleCore->findModulePreview($module->vendor, $module->module_name);
            if (!empty($preview)) {
                $module->preview = $preview;
            }
            $module->params = $moduleCore->getModuleParams($module->vendor, $module->module_name);
            $module->versionControl = $moduleCore->getVersionControl();
        }

        $this->design->assign('modules', $modulesList);

        $this->response->setContent($this->design->fetch('modules.tpl'));
    }
}
