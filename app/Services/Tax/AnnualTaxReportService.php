<?php

namespace App\Services\Tax;

use App\Models\Customer;
use App\Models\IncomeData;
use App\Models\TaxCalculation;
use Illuminate\Support\Carbon;
use RuntimeException;

class AnnualTaxReportService
{
    /**
     * Builds the "Conto Economico" (income statement) report data for a
     * customer/year — ported from VTMS's Tax_calculations::annual_tax_report_pdf().
     * Costs are derived from the coefficient of profitability: VTMS treats
     * (100 - coefficient)% of total sales as the deemed cost base under
     * Regime Forfettario, so taxable_income is always total_sales - costs.
     */
    public function build(Customer $customer, int $year): array
    {
        $taxCalculation = TaxCalculation::where('customer_id', $customer->id)
            ->where('tax_year', $year)
            ->first();

        if (! $taxCalculation) {
            throw new RuntimeException("No tax calculation found for customer #{$customer->id}, year {$year}.");
        }

        $totalSales = (float) $taxCalculation->total_sales;
        $coefficient = (float) $taxCalculation->coefficient_used;
        $costs = round($totalSales * (100 - $coefficient) / 100, 2);
        $netIncome = (float) $taxCalculation->taxable_income;

        $dateColumn = IncomeData::revenueDateColumn();
        $period = IncomeData::where('customer_id', $customer->id)
            ->revenueForYear($year)
            ->selectRaw("MIN({$dateColumn}) as first_date, MAX({$dateColumn}) as last_date")
            ->first();

        if ($period && $period->first_date) {
            $periodStart = Carbon::parse($period->first_date)->format('d/m/Y');
            $periodEnd = Carbon::parse($period->last_date)->format('d/m/Y');
        } else {
            $periodStart = $customer->business_start_date && $customer->business_start_date->year === $year
                ? $customer->business_start_date->format('d/m/Y')
                : "01/01/{$year}";
            $periodEnd = "31/12/{$year}";
        }

        $activityMap = [
            'gestione_separata' => 'prestazioni di servizi',
            'commercianti' => 'commercio',
            'artigiani' => 'artigianato',
        ];
        $customerTypeValue = $customer->customer_type?->value;
        $activityDescription = $activityMap[$customerTypeValue] ?? ($customerTypeValue ?? '');

        return [
            'customer' => $customer,
            'tax_calculation' => $taxCalculation,
            'year' => $year,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'total_sales' => $totalSales,
            'coefficient' => $coefficient,
            'costs' => $costs,
            'gross_income' => $taxCalculation->grossIncome(),
            'inps_deducted' => (float) $taxCalculation->inps_deducted,
            'net_income' => $netIncome,
            'ateco_code' => $customer->ateco_code ?: $customer->inail_ateco_code,
            'vat_number' => $customer->vat_no,
            // This app only models Regime Forfettario customers (see
            // TaxCalculationService docblock) — there is no per-customer
            // tax_regime column, so this is always the one legal regime.
            'tax_regime' => 'regime_forfettario',
            'activity_description' => $activityDescription,
        ];
    }

    public function filename(Customer $customer, int $year): string
    {
        return 'Conto_Economico_'.preg_replace('/[^A-Za-z0-9_-]+/', '_', $customer->name).'_'.$year.'.pdf';
    }
}
