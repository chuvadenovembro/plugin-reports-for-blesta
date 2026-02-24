<?php
/**
 * Relatorios parent controller
 *
 * @package blesta
 * @subpackage plugins.relatorios
 * @copyright Copyright (c) 2025, Carlos Sidnei
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */
class RelatoriosController extends AppController
{
    public function preAction()
    {
        $this->structure->setDefaultView(APPDIR);
        parent::preAction();
        $this->view->view = "default";
        $this->requireLogin();

        Language::loadLang(
            [Loader::fromCamelCase(get_class($this))],
            null,
            dirname(__FILE__) . DS . 'language' . DS
        );
        Language::loadLang(
            'relatorios_controller',
            null,
            dirname(__FILE__) . DS . 'language' . DS
        );
    }
}
