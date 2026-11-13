<?php

namespace App\Services\Tax;

use App\Models\Customer;
use App\Models\IncomeData;
use Illuminate\Support\Carbon;

class IncomePredictionService
{
    /**
     * Ported from VTMS's Tax_calculations_model::predictTaxableIncome():
     * extrapolates a full year from the average of the months that already
     * have income data. Months are grouped in PHP rather than with MONTH()
     * so the query stays portable across MySQL and SQLite.
     */
    public function predict(Customer $customer, int $year): ?array
    {
        $dateColumn = IncomeData::revenueDateColumn();
        $rows = IncomeData::where('customer_id', $customer->id)
            ->revenueForYear($year)
            ->get([$dateColumn, 'taxable_sales']);

        if ($rows->isEmpty()) {
            return null;
        }

        $monthly = $rows
            ->groupBy(fn (IncomeData $row) => Carbon::parse($row->{$dateColumn})->month)
            ->map(fn ($group, $month) => [
                'month' => (int) $month,
                'sales' => round((float) $group->sum('taxable_sales'), 2),
                'entries' => $group->count(),
            ])
            ->sortKeys()
            ->values();

        $monthsWithData = $monthly->pluck('month')->all();
        $missingMonths = array_values(array_diff(range(1, 12), $monthsWithData));

        $currentTotalSales = round((float) $monthly->sum('sales'), 2);
        $averageMonthlySales = round($currentTotalSales / count($monthsWithData), 2);
        $predictedAdditionalSales = round($averageMonthlySales * count($missingMonths), 2);
        $predictedTotalSales = round($currentTotalSales + $predictedAdditionalSales, 2);

        $coefficient = (float) $customer->coefficient_of_profitability;
        $taxRate = (float) $customer->tax_rate;
        $predictedTaxableIncome = round($predictedTotalSales * $coefficient / 100, 2);

        return [
            'current_total_sales' => $currentTotalSales,
            'current_taxable_income' => round($currentTotalSales * $coefficient / 100, 2),
            'months_with_data' => $monthsWithData,
            'missing_months' => $missingMonths,
            'average_monthly_sales' => $averageMonthlySales,
            'predicted_additional_sales' => $predictedAdditionalSales,
            'predicted_total_sales' => $predictedTotalSales,
            'predicted_taxable_income' => $predictedTaxableIncome,
            'predicted_tax_due' => round($predictedTaxableIncome * $taxRate / 100, 2),
            'coefficient' => $coefficient,
            'monthly_sales' => $monthly->all(),
        ];
    }
}
