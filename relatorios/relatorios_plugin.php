<?php
/**
 * Relatorios plugin handler
 *
 * @package blesta
 * @subpackage plugins.relatorios
 * @copyright Copyright (c) 2025, Carlos Sidnei
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */
class RelatoriosPlugin extends Plugin
{
    public function __construct()
    {
        $this->loadConfig(dirname(__FILE__) . DS . 'config.json');
        Language::loadLang('relatorios_plugin', null, dirname(__FILE__) . DS . 'language' . DS);
    }

    public function install($plugin_id)
    {
        $this->addPermissions($plugin_id);
    }

    public function uninstall($plugin_id, $last_instance)
    {
        $this->removePermissions($plugin_id);
    }

    public function getActions()
    {
        return [
            [
                'action' => 'nav_secondary_staff',
                'uri' => 'plugin/relatorios/admin_main/',
                'name' => 'RelatoriosPlugin.nav_secondary_staff.admin_main',
                'options' => ['parent' => 'tools/']
            ]
        ];
    }

    /**
     * Registers permissions under the Tools group during install.
     *
     * @param int $plugin_id
     */
    private function addPermissions($plugin_id)
    {
        Loader::loadModels($this, ['Permissions']);

        $group = $this->Permissions->getGroupByAlias('admin_tools');
        if (!$group) {
            return;
        }

        $this->Permissions->add([
            'plugin_id' => $plugin_id,
            'group_id' => $group->id,
            'name' => Language::_('RelatoriosPlugin.permission.admin_main', true),
            'alias' => 'relatorios.admin_main',
            'action' => '*'
        ]);
    }

    /**
     * Removes permissions during uninstall.
     *
     * @param int $plugin_id
     */
    private function removePermissions($plugin_id)
    {
        Loader::loadModels($this, ['Permissions']);

        $permission = $this->Permissions->getByAlias('relatorios.admin_main', $plugin_id);
        if ($permission) {
            $this->Permissions->delete($permission->id);
        }
    }
}
