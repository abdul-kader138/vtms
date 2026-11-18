<?php

namespace App\Services\Tax;

use App\Enums\CustomerType;
use App\Enums\InpsCalculationMethod;
use App\Models\Customer;
use App\Models\IncomeData;
use App\Models\InpsContributionRule;
use App\Support\Tax\TaxRules;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class IncomePredictionService
{
    public function __construct(private readonly InpsCalculationService $inpsCalculationService) {}

    /**
     * Ported from VTMS's Tax_calculations_model::predictTaxableIncome():
     * extrapolates a full year from the average of the months that already
     * have income data. Months are grouped in PHP rather than with MONTH()
     * so the query stays portable across MySQL and SQLite.
     *
     * Kept exactly as-is — this feeds TaxOverviewService::build()'s
     * existing 'prediction' key and eligibilityWarnings()'s €85k check,
     * both already shown on the real dashboard. predictRepresentative()
     * below is the new, separate, year-over-year-aware projection; it does
     * not replace or alter this one, so nothing currently displayed changes.
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

    /**
     * The new "Prediction" section's data — entirely separate from
     * predict() above and from any real calculation. Never writes to the
     * database (InpsCalculationService's compute*() methods are pure).
     *
     * Year-over-year aware: for a missing month, uses the same month last
     * year scaled by the overall growth rate seen across months present in
     * BOTH years — but only when at least 3 such overlapping months exist
     * and their total wasn't zero (otherwise a growth rate from 1-2 months
     * is too noisy to trust, matching the plan's guard). Falls back to a
     * simple average-of-known-months per missing month otherwise, same
     * method predict() already uses.
     *
     * @return array{
     *     monthly_projected: list<array{month: int, sales: float, is_actual: bool, method: string}>,
     *     predicted_total_sales: float, predicted_taxable_income: float, predicted_tax_due: float,
     *     predicted_inps: array{amount_after_discount: float, fixed_amount: ?float, variable_amount: ?float, method: string}|null,
     * }|null
     */
    public function predictRepresentative(Customer $customer, int $year): ?array
    {
        $thisYear = $this->monthlySales($customer, $year);
        if ($thisYear->isEmpty()) {
            return null;
        }

        $lastYear = $this->monthlySales($customer, $year - 1);

        $overlapMonths = $thisYear->keys()->intersect($lastYear->keys());
        $overlapLastYearSum = $overlapMonths->sum(fn ($m) => $lastYear->get($m, 0.0));
        $useYoy = $overlapMonths->count() >= 3 && $overlapLastYearSum > 0;
        $growthRate = $useYoy ? $overlapMonths->sum(fn ($m) => $thisYear->get($m, 0.0)) / $overlapLastYearSum : null;

        $knownSum = $thisYear->sum();
        $knownCount = $thisYear->count();
        $averageMonthlySales = $knownCount > 0 ? round($knownSum / $knownCount, 2) : 0.0;

        $monthlyProjected = collect(range(1, 12))->map(function (int $month) use ($thisYear, $lastYear, $useYoy, $growthRate, $averageMonthlySales) {
            if ($thisYear->has($month)) {
                return ['month' => $month, 'sales' => round($thisYear->get($month), 2), 'is_actual' => true, 'method' => 'actual'];
            }

            if ($useYoy && $lastYear->has($month)) {
                return ['month' => $month, 'sales' => round($lastYear->get($month) * $growthRate, 2), 'is_actual' => false, 'method' => 'year_over_year'];
            }

            return ['month' => $month, 'sales' => $averageMonthlySales, 'is_actual' => false, 'method' => 'average'];
        })->values();

        $predictedTotalSales = round((float) $monthlyProjected->sum('sales'), 2);
        $coefficient = (float) $customer->coefficient_of_profitability;
        $predictedTaxableIncome = round($predictedTotalSales * $coefficient / 100, 2);
        $taxRate = $customer->taxRateFor($year);

        return [
            'monthly_projected' => $monthlyProjected->all(),
            'predicted_total_sales' => $predictedTotalSales,
            'predicted_taxable_income' => $predictedTaxableIncome,
            'predicted_tax_due' => round($predictedTaxableIncome * $taxRate / 100, 2),
            'predicted_inps' => $this->predictedInps($customer, $year, $predictedTaxableIncome),
        ];
    }

    /** @return Collection<int, float> month (1-12) => taxable_sales */
    private function monthlySales(Customer $customer, int $year): Collection
    {
        $dateColumn = IncomeData::revenueDateColumn();

        return IncomeData::where('customer_id', $customer->id)
            ->revenueForYear($year)
            ->get([$dateColumn, 'taxable_sales'])
            ->groupBy(fn (IncomeData $row) => (int) Carbon::parse($row->{$dateColumn})->month)
            ->map(fn ($group) => round((float) $group->sum('taxable_sales'), 2));
    }

    /**
     * Projects INPS from a predicted taxable income using the exact same
     * customer-type logic InpsCalculationService::calculateForYear() uses
     * — via its pure compute*() methods, which never persist anything.
     */
    private function predictedInps(Customer $customer, int $year, float $predictedTaxableIncome): ?array
    {
        if (TaxRules::isLaw() && in_array($customer->customer_type, [CustomerType::Artigiani, CustomerType::Commercianti], true)
            && ($rule = InpsContributionRule::for($customer->customer_type, $year))) {
            $data = $this->inpsCalculationService->computeFixedPlusPercentage($customer, $year, $predictedTaxableIncome, $rule);

            return [
                'amount_after_discount' => (float) $data['inps_amount_after_discount'],
                'fixed_amount' => (float) $data['fixed_amount'],
                'variable_amount' => (float) $data['variable_amount'],
                'method' => $data['calculation_method'] === InpsCalculationMethod::FixedPlusPercentage ? 'fixed_plus_percentage' : 'percentage',
            ];
        }

        $data = $this->inpsCalculationService->computeSlabPercentage($customer, $year, $predictedTaxableIncome);

        return [
            'amount_after_discount' => (float) $data['inps_amount_after_discount'],
            'fixed_amount' => null,
            'variable_amount' => null,
            'method' => $data['calculation_method'] instanceof InpsCalculationMethod ? $data['calculation_method']->value : (string) $data['calculation_method'],
        ];
    }
}
