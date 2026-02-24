<?php
/**
 * Relatorios Admin Main Controller
 *
 * @package blesta
 * @subpackage plugins.relatorios
 * @copyright Copyright (c) 2025, Carlos Sidnei
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */
class AdminMain extends RelatoriosController
{
    /**
     * Available report identifiers mapped to their model classes.
     *
     * @var array
     */
    private $reports = [
        'income_forecast' => 'RelatoriosIncomeForecast',
        'annual_income' => 'RelatoriosAnnualIncome',
        'upcoming_invoices' => 'RelatoriosUpcomingInvoices'
    ];

    private function init()
    {
        Loader::loadHelpers($this, ['Form', 'Html', 'CurrencyFormat', 'Date']);
        Loader::loadModels($this, ['Currencies', 'Companies']);
    }

    public function index()
    {
        $this->init();

        $company_id = Configure::get('Blesta.company_id');
        $report = $this->post['report'] ?? ($this->get[0] ?? 'income_forecast');

        if (!isset($this->reports[$report])) {
            $report = 'income_forecast';
        }

        // Load the model for the selected report
        Loader::loadModels($this, ['Relatorios.' . $this->reports[$report]]);

        // Build report selector options from language keys
        $report_options = [];
        foreach (array_keys($this->reports) as $key) {
            $report_options[$key] = Language::_('AdminMain.reports.' . $key, true);
        }

        $this->set('report', $report);
        $this->set('report_options', $report_options);
        $this->set('plugin_uri', $this->base_uri . 'plugin/relatorios/admin_main/');

        // Determine currency
        $currency = $this->post['currency'] ?? null;
        if (empty($currency)) {
            $default_currency = $this->Companies->getSetting($company_id, 'default_currency');
            $currency = $default_currency ? $default_currency->value : 'USD';
        }

        $this->set('currencies', $this->Form->collapseObjectArray(
            $this->Currencies->getAll($company_id),
            'code',
            'code'
        ));

        // Dispatch to the appropriate report handler
        $method = 'prepare' . str_replace('_', '', ucwords($report, '_'));
        $this->{$method}($company_id, $currency);

        return $this->renderAjaxWidgetIfAsync(isset($this->get[0]) && $this->get[0] == 'async');
    }

    /**
     * Prepares data for the Income Forecast report.
     *
     * @param int $company_id
     * @param string $currency
     */
    private function prepareIncomeForecast($company_id, $currency)
    {
        $months = (int)($this->post['months'] ?? 12);

        $vars = (object)['currency' => $currency, 'months' => $months];
        $this->set('vars', $vars);

        $forecast_data = $this->RelatoriosIncomeForecast->getForecast(
            $company_id,
            $currency,
            $months
        );

        $this->set('forecast_data', $forecast_data);
        $this->set('overall_total', $this->RelatoriosIncomeForecast->getOverallTotal($forecast_data));
    }

    /**
     * Prepares data for the Annual Income report.
     *
     * @param int $company_id
     * @param string $currency
     */
    private function prepareAnnualIncome($company_id, $currency)
    {
        $year = (int)($this->post['year'] ?? date('Y'));

        $vars = (object)['currency' => $currency, 'year' => $year];
        $this->set('vars', $vars);

        $available_years = $this->RelatoriosAnnualIncome->getAvailableYears($company_id, $currency);
        $this->set('available_years', $available_years);

        $report_data = $this->RelatoriosAnnualIncome->getReport($company_id, $currency, $year);
        $this->set('annual_data', $report_data['months']);
        $this->set('annual_totals', $report_data['totals']);
    }

    /**
     * Prepares data for the Upcoming Automatic Invoices report.
     *
     * @param int $company_id
     * @param string $currency
     */
    private function prepareUpcomingInvoices($company_id, $currency)
    {
        $start_date = $this->post['start_date'] ?? date('Y-m-d');
        $end_date = $this->post['end_date'] ?? date('Y-m-d', strtotime('+30 days'));

        $vars = (object)['currency' => $currency, 'start_date' => $start_date, 'end_date' => $end_date];
        $this->set('vars', $vars);

        $upcoming = $this->RelatoriosUpcomingInvoices->getUpcoming($company_id, $currency, $start_date, $end_date);
        $past_due = $this->RelatoriosUpcomingInvoices->getPastDue($company_id, $currency, $start_date);

        $this->set('upcoming_data', $upcoming);
        $this->set('past_due_data', $past_due);
    }
}
