<?php
/**
 * Relatorios Parent Model
 *
 * @package blesta
 * @subpackage plugins.relatorios
 * @copyright Copyright (c) 2025, Carlos Sidnei
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */
class RelatoriosModel extends AppModel
{
    public function __construct()
    {
        parent::__construct();
        Language::loadLang([Loader::fromCamelCase(get_class($this))], null, dirname(__FILE__) . DS . 'language' . DS);
    }

    /**
     * Returns the English month name for a given month number.
     *
     * @param int $month Month number (1-12)
     * @return string
     */
    protected function getMonthName($month)
    {
        $names = [
            1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April',
            5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August',
            9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December'
        ];
        return $names[(int)$month] ?? '';
    }
}
