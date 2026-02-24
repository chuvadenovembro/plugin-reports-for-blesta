<?php
/**
 * Relatorios Annual Income Model
 *
 * @package blesta
 * @subpackage plugins.relatorios
 * @copyright Copyright (c) 2025, Carlos Sidnei
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */
class RelatoriosAnnualIncome extends RelatoriosModel
{
    public function __construct()
    {
        parent::__construct();
        Loader::loadComponents($this, ['Record']);
    }

    /**
     * Gera o relatório de receita anual para o ano informado.
     *
     * @param int    $company_id
     * @param string $currency
     * @param int    $year       Ano de referência
     * @return array Contém 'months' (dados mensais) e 'totals' (resumo do ano)
     */
    public function getReport($company_id, $currency, $year)
    {
        // Cast defensivo: garante que $year é inteiro antes de qualquer uso
        $year = (int)$year;

        $income  = $this->getMonthlyIncome($company_id, $currency, $year);
        $refunds = $this->getMonthlyRefunds($company_id, $currency, $year);

        $months = [];
        $totals = ['amount_in' => 0.0, 'refunds' => 0.0, 'net' => 0.0];

        for ($m = 1; $m <= 12; $m++) {
            $amount_in = $income[$m]  ?? 0.0;
            $refund    = $refunds[$m] ?? 0.0;
            $net       = $amount_in - $refund;

            $months[] = [
                'month'      => $m,
                'month_name' => $this->getMonthName($m),
                'amount_in'  => $amount_in,
                'refunds'    => $refund,
                'net'        => $net,
            ];

            $totals['amount_in'] += $amount_in;
            $totals['refunds']   += $refund;
            $totals['net']       += $net;
        }

        return ['months' => $months, 'totals' => $totals];
    }

    /**
     * Retorna os anos disponíveis com base nas transações aprovadas da empresa.
     * Sempre inclui o ano corrente, mesmo que não haja transações ainda.
     *
     * @param int    $company_id
     * @param string $currency
     * @return array Anos disponíveis em ordem decrescente, indexados pelo próprio valor
     */
    public function getAvailableYears($company_id, $currency)
    {
        $result = $this->Record->select([
                'MIN(YEAR(transactions.date_added))' => 'min_year',
                'MAX(YEAR(transactions.date_added))' => 'max_year',
            ], false)
            ->from('transactions')
            ->innerJoin('clients',       'clients.id',            '=', 'transactions.client_id',       false)
            ->innerJoin('client_groups', 'client_groups.id',      '=', 'clients.client_group_id',      false)
            ->where('client_groups.company_id',  '=', $company_id)
            ->where('transactions.currency',     '=', $currency)
            ->where('transactions.status',       '=', 'approved')
            ->fetch();

        $current_year = (int)date('Y');

        if (!$result || !$result->min_year) {
            return [$current_year => $current_year];
        }

        // Garante que o ano atual sempre consta na lista, mesmo sem transações ainda
        $max = max((int)$result->max_year, $current_year);
        $min = (int)$result->min_year;

        $years = [];
        for ($y = $max; $y >= $min; $y--) {
            $years[$y] = $y;
        }

        return $years;
    }

    /**
     * Retorna os totais mensais de receita (transações debit aprovadas).
     * Exclui transações do tipo 'credit' para não inflar o valor recebido.
     *
     * @param int    $company_id
     * @param string $currency
     * @param int    $year       Já sanitizado como (int) pelo método público — seguro para concatenação
     * @return array Indexado pelo número do mês (1-12)
     */
    private function getMonthlyIncome($company_id, $currency, $year)
    {
        $results = $this->Record->select([
                'MONTH(transactions.date_added)' => 'month',
                'SUM(transactions.amount)'       => 'total',
            ], false)
            ->from('transactions')
            ->innerJoin('clients',           'clients.id',             '=', 'transactions.client_id',         false)
            ->innerJoin('client_groups',     'client_groups.id',       '=', 'clients.client_group_id',        false)
            ->leftJoin('transaction_types',  'transaction_types.id',   '=', 'transactions.transaction_type_id', false)
            ->where('client_groups.company_id',  '=', $company_id)
            ->where('transactions.currency',     '=', $currency)
            ->where('transactions.status',       '=', 'approved')
            // Exclui transações classificadas como crédito interno; mantém as sem tipo definido
            ->open()
                ->where('transaction_types.type', '!=', 'credit')
                ->orWhere('transaction_types.type', '=', null)
            ->close()
            // Range de data seguro: $year já é (int), sem risco de injeção
            ->where('transactions.date_added', '>=', $year . '-01-01 00:00:00')
            ->where('transactions.date_added', '<=', $year . '-12-31 23:59:59')
            ->group(['MONTH(transactions.date_added)'])
            ->getStatement();

        $data = [];
        foreach ($results as $row) {
            $data[(int)$row->month] = (float)$row->total;
        }
        return $data;
    }

    /**
     * Retorna os totais mensais de reembolsos (transações com status refunded ou returned).
     *
     * @param int    $company_id
     * @param string $currency
     * @param int    $year       Já sanitizado como (int) pelo método público — seguro para concatenação
     * @return array Indexado pelo número do mês (1-12)
     */
    private function getMonthlyRefunds($company_id, $currency, $year)
    {
        $results = $this->Record->select([
                'MONTH(transactions.date_added)' => 'month',
                'SUM(transactions.amount)'       => 'total',
            ], false)
            ->from('transactions')
            ->innerJoin('clients',       'clients.id',       '=', 'transactions.client_id',   false)
            ->innerJoin('client_groups', 'client_groups.id', '=', 'clients.client_group_id',  false)
            ->where('client_groups.company_id', '=', $company_id)
            ->where('transactions.currency',    '=', $currency)
            ->open()
                ->where('transactions.status', '=', 'refunded')
                ->orWhere('transactions.status', '=', 'returned')
            ->close()
            // Range de data seguro: $year já é (int), sem risco de injeção
            ->where('transactions.date_added', '>=', $year . '-01-01 00:00:00')
            ->where('transactions.date_added', '<=', $year . '-12-31 23:59:59')
            ->group(['MONTH(transactions.date_added)'])
            ->getStatement();

        $data = [];
        foreach ($results as $row) {
            $data[(int)$row->month] = (float)$row->total;
        }
        return $data;
    }
}