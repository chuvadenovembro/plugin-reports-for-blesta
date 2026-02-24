<?php
/**
 * Relatorios Income Forecast Model
 *
 * @package blesta
 * @subpackage plugins.relatorios
 * @copyright Copyright (c) 2025, Carlos Sidnei
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */
class RelatoriosIncomeForecast extends RelatoriosModel
{
    // Períodos de recorrência (em meses) exibidos no breakdown do relatório
    private $periods = [1, 3, 6, 12, 24, 36];

    public function __construct()
    {
        parent::__construct();
        Loader::loadComponents($this, ['Record']);
    }

    /**
     * Gera a projeção de receita para a empresa e moeda informadas.
     *
     * @param int    $company_id ID da empresa no Blesta
     * @param string $currency   Código da moeda (ex: BRL, USD)
     * @param int    $months     Horizonte da projeção em meses (padrão: 12)
     * @return array
     */
    public function getForecast($company_id, $currency, $months = 12)
    {
        $now_month = (int)date('m');
        $now_year  = (int)date('Y');

        // Define o limite final da projeção
        $end_time  = mktime(0, 0, 0, $now_month + $months, 1, $now_year);
        $end_year  = (int)date('Y', $end_time);
        $end_month = (int)date('m', $end_time);

        $totals = [];

        $services = $this->getActiveServices($company_id, $currency);
        foreach ($services as $service) {
            // Preço efetivo: override manual > preço de renovação > preço base
            $price = $service->override_price !== null
                ? $service->override_price
                : ($service->price_renews !== null ? $service->price_renews : $service->price);

            $recurrence = $this->getRecurrenceInMonths($service->term, $service->period);

            // Serviços com período não mapeável em meses (ex: diário/semanal curto)
            // são ignorados silenciosamente pois não há representação válida em meses inteiros
            if ($recurrence <= 0) {
                continue;
            }

            $this->projectRenewals(
                $service->date_renews,
                (float)$price * (int)$service->qty,
                $recurrence,
                $totals,
                $end_year,
                $end_month
            );
        }

        $forecast   = [];
        $cumulative = 0;

        for ($i = 0; $i <= $months; $i++) {
            $time  = mktime(0, 0, 0, $now_month + $i, 1, $now_year);
            $year  = (int)date('Y', $time);
            $month = (int)date('m', $time);

            $periods       = $this->getMonthPeriods($totals, $year, $month);
            $monthly_total = array_sum($periods);
            $cumulative   += $monthly_total;

            $forecast[] = [
                'year'          => $year,
                'month'         => $month,
                'month_name'    => $this->getMonthName($month),
                'periods'       => $periods,
                'monthly_total' => $monthly_total,
                'cumulative'    => $cumulative,
            ];
        }

        return $forecast;
    }

    /**
     * Calcula o total geral somando todos os meses da projeção.
     *
     * @param array $forecast_data Retorno de getForecast()
     * @return float
     */
    public function getOverallTotal($forecast_data)
    {
        $total = 0.0;
        foreach ($forecast_data as $row) {
            $total += $row['monthly_total'];
        }
        return $total;
    }

    /**
     * Retorna todos os serviços ativos e recorrentes da empresa/moeda informadas.
     * Filtra no banco serviços sem date_renews, evitando processamento desnecessário no PHP.
     *
     * @param int    $company_id
     * @param string $currency
     * @return PDOStatement
     */
    private function getActiveServices($company_id, $currency)
    {
        return $this->Record->select([
                'services.id',
                'services.date_renews',
                'services.qty',
                'services.override_price',
                'pricings.term',
                'pricings.period',
                'pricings.price',
                'pricings.price_renews',
            ])
            ->from('services')
            ->innerJoin('package_pricing', 'package_pricing.id',  '=', 'services.pricing_id',        false)
            ->innerJoin('pricings',        'pricings.id',          '=', 'package_pricing.pricing_id', false)
            ->innerJoin('packages',        'packages.id',          '=', 'package_pricing.package_id', false)
            ->where('services.status',      '=',  'active')
            ->where('services.date_renews', '!=', null)   // descarta no banco serviços sem data de renovação
            ->where('pricings.period',      '!=', 'onetime')
            ->where('pricings.currency',    '=',  $currency)
            ->where('packages.company_id',  '=',  $company_id)
            ->getStatement();
    }

    /**
     * Projeta os valores de renovação de um serviço no array $totals,
     * avançando mês a mês pela recorrência até atingir o limite definido.
     *
     * @param string $date_renews Data de renovação (formato: YYYY-MM-DD HH:MM:SS)
     * @param float  $price       Valor total da renovação (preço × quantidade)
     * @param int    $recurrence  Intervalo em meses entre renovações
     * @param array  &$totals     Array acumulador indexado por [ano][mês][recorrência]
     * @param int    $end_year    Ano limite da projeção
     * @param int    $end_month   Mês limite da projeção
     */
    private function projectRenewals($date_renews, $price, $recurrence, &$totals, $end_year, $end_month)
    {
        if ($price <= 0) {
            return;
        }

        // Extrai ano e mês diretamente da string sem conversão de timezone
        $year  = (int)substr($date_renews, 0, 4);
        $month = (int)substr($date_renews, 5, 2);

        for ($i = 0; ; $i += $recurrence) {
            $new_time = mktime(0, 0, 0, $month + $i, 1, $year);

            if ($new_time === false) {
                break;
            }

            $new_year  = (int)date('Y', $new_time);
            $new_month = (int)date('m', $new_time);

            // Para ao ultrapassar o limite da projeção
            if ($new_year > $end_year || ($new_year === $end_year && $new_month > $end_month)) {
                break;
            }

            $totals[$new_year][$new_month][$recurrence] = ($totals[$new_year][$new_month][$recurrence] ?? 0) + $price;
        }
    }

    /**
     * Converte o termo e período de um pricing em número de meses de recorrência.
     * Períodos baseados em semanas e dias são aproximações e podem resultar em 0.
     *
     * @param int    $term   Valor numérico do termo (ex: 1, 3, 6)
     * @param string $period Tipo do período (month, year, week, day)
     * @return int           Recorrência em meses (0 se não mapeável)
     */
    private function getRecurrenceInMonths($term, $period)
    {
        $term = (int)$term;

        switch ($period) {
            case 'month':
                return $term;
            case 'year':
                return $term * 12;
            case 'week':
                return (int)round($term / 4);
            case 'day':
                return (int)round($term / 30);
            default:
                return 0;
        }
    }

    /**
     * Retorna o breakdown por recorrência para um determinado ano/mês.
     *
     * @param array $totals Array acumulador gerado por projectRenewals()
     * @param int   $year
     * @param int   $month
     * @return array Chave = recorrência em meses, valor = total acumulado
     */
    private function getMonthPeriods($totals, $year, $month)
    {
        $result = [];
        foreach ($this->periods as $period) {
            $result[$period] = $totals[$year][$month][$period] ?? 0;
        }
        return $result;
    }
}