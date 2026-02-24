<?php
/**
 * Relatorios Upcoming Invoices Model
 *
 * Fornece uma previsão de renovações recorrentes com base na tabela `services`
 * e na coluna `date_renews`, excluindo serviços que já possuem fatura ativa
 * gerada para o mesmo período de renovação.
 *
 * @package blesta
 * @subpackage plugins.relatorios
 * @copyright Copyright (c) 2025, Carlos Sidnei
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */
class RelatoriosUpcomingInvoices extends RelatoriosModel
{
    public function __construct()
    {
        parent::__construct();
        Loader::loadComponents($this, ['Record']);
    }

    /**
     * Retorna serviços com renovação prevista dentro do período informado,
     * excluindo aqueles que já possuem fatura ativa gerada para a mesma data.
     *
     * @param int    $company_id ID da empresa no Blesta
     * @param string $currency   Código da moeda (ex: BRL, USD)
     * @param string $start_date Data inicial no formato YYYY-MM-DD
     * @param string $end_date   Data final no formato YYYY-MM-DD
     * @return array             Array com 'services' e 'total'
     */
    public function getUpcoming($company_id, $currency, $start_date, $end_date)
    {
        return $this->getServices(
            $company_id,
            $currency,
            $start_date . ' 00:00:00',
            $end_date . ' 23:59:59'
        );
    }

    /**
     * Retorna serviços com renovação em atraso (date_renews anterior à data informada),
     * excluindo aqueles que já possuem fatura ativa gerada.
     *
     * @param int    $company_id ID da empresa no Blesta
     * @param string $currency   Código da moeda (ex: BRL, USD)
     * @param string $start_date Data de corte no formato YYYY-MM-DD
     * @return array             Array com 'services' e 'total'
     */
    public function getPastDue($company_id, $currency, $start_date)
    {
        return $this->getServices(
            $company_id,
            $currency,
            null,
            $start_date . ' 00:00:00',
            true
        );
    }

    /**
     * Método central de consulta. Busca serviços ativos ou suspensos com
     * data de renovação dentro do intervalo informado, aplicando anti-join
     * para excluir serviços que já possuem fatura ativa para a mesma data de renovação.
     *
     * A lógica de anti-join funciona assim:
     *   - LEFT JOIN em `invoice_lines` pelo service_id
     *   - LEFT JOIN em `invoices` pelo invoice_id, com condições no ON:
     *       * status da fatura não pode ser 'void' ou 'draft'
     *       * a data de vencimento da fatura deve corresponder à data de renovação do serviço
     *   - WHERE invoices.id IS NULL → serviços SEM fatura ativa para aquela renovação
     *
     * @param int         $company_id      ID da empresa
     * @param string      $currency        Moeda da consulta
     * @param string|null $start_datetime  Data/hora inicial (nulo para past due)
     * @param string      $end_datetime    Data/hora final ou data de corte
     * @param bool        $is_past_due     Se verdadeiro, filtra renovações em atraso
     * @return array
     */
    private function getServices($company_id, $currency, $start_datetime, $end_datetime, $is_past_due = false)
    {
        // Idioma ativo no Blesta, usado para buscar o nome do pacote na língua correta
        $lang = Configure::get('Blesta.language') ?: 'en_us';

        $this->Record->select([
                'services.date_renews',
                'clients.id'        => 'client_id',
                'clients.id_value'  => 'client_id_value',
                'contacts.first_name',
                'contacts.last_name',
                'package_names.name' => 'package_name',
                'services.id'       => 'service_id',
                'services.qty',
                // Preço efetivo: override manual > preço de renovação > preço base
                'COALESCE(services.override_price, pricings.price_renews, pricings.price)' => 'price',
                'pricings.currency',
                'pricings.period',
                'pricings.term',
            ], false)
            ->from('services')

            // Dados do cliente e grupo (usado para filtrar pela empresa)
            ->innerJoin('clients',       'clients.id',             '=', 'services.client_id',        false)
            ->innerJoin('client_groups', 'client_groups.id',       '=', 'clients.client_group_id',   false)

            // Apenas o contato primário do cliente
            ->innerJoin('contacts',      'contacts.client_id',     '=', 'clients.id',                false)

            // Relacionamento com precificação e pacote
            ->innerJoin('package_pricing', 'package_pricing.id',  '=', 'services.pricing_id',       false)
            ->innerJoin('pricings',        'pricings.id',          '=', 'package_pricing.pricing_id', false)
            ->innerJoin('packages',        'packages.id',          '=', 'package_pricing.package_id', false)

            // Nome do pacote no idioma configurado (opcional: pode não existir tradução)
            ->leftJoin('package_names', 'package_names.package_id', '=', 'packages.id', false)
                ->on('package_names.lang', '=', $lang)

            // Anti-join: verifica se já existe fatura ativa para esta renovação
            // Se encontrar correspondência no JOIN, invoices.id será preenchido.
            // O WHERE invoices.id IS NULL filtra apenas os SEM fatura.
            ->leftJoin('invoice_lines', 'invoice_lines.service_id', '=', 'services.id', false)
            ->leftJoin('invoices',      'invoices.id', '=', 'invoice_lines.invoice_id', false)
                // Exclui faturas canceladas ou em rascunho do anti-join
                ->on('invoices.status', 'not in', ['void', 'draft'])
                // Compara apenas faturas com vencimento igual ao dia da renovação do serviço
                ->on('DATE(invoices.date_due)', '=', 'DATE(services.date_renews)', false)

            // Filtros principais
            ->where('contacts.contact_type',   '=',     'primary')
            ->where('services.status',         'in',    ['active', 'suspended'])
            ->where('services.date_canceled',  '=',     null)
            ->where('services.date_renews',    '!=',    null)
            ->where('pricings.period',         '!=',    'onetime')
            ->where('pricings.currency',       '=',     $currency)
            ->where('client_groups.company_id','=',     $company_id)

            // Anti-join: inclui apenas serviços SEM fatura ativa para esta data de renovação
            ->where('invoices.id', '=', null);

        // Filtro de intervalo de datas de renovação
        if ($is_past_due) {
            // Renovações atrasadas: date_renews anterior à data de corte
            $this->Record->where('services.date_renews', '<', $end_datetime);
        } else {
            // Renovações futuras: date_renews dentro do período selecionado
            $this->Record->where('services.date_renews', '>=', $start_datetime)
                         ->where('services.date_renews', '<=', $end_datetime);
        }

        $results = $this->Record
            // GROUP BY evita duplicatas caso o serviço apareça em múltiplos invoice_lines
            ->group(['services.id'])
            ->order(['services.date_renews' => 'ASC'])
            ->getStatement()
            ->fetchAll(PDO::FETCH_ASSOC);

        // Calcula o total por linha e o total geral do período
        $total_amount = 0.0;
        foreach ($results as &$row) {
            $row['total'] = (float)$row['price'] * (int)$row['qty'];
            $total_amount += $row['total'];
        }
        unset($row);

        return [
            'services' => $results,
            'total'    => $total_amount,
        ];
    }
}