<?php

namespace App\Services\Tax;

use App\Models\Customer;
use App\Models\IncomeClosing;
use Illuminate\Support\Carbon;

/**
 * Customer self-service "this month is final" marker — purely an input
 * signal for IncomePredictionService's year-over-year math. Does not lock
 * or filter income_data in any way, and nothing in the real calculation
 * pipeline (TaxCalculationOrchestrator) reads this. Close/reopen freely,
 * no approval step.
 */
class IncomeClosingService
{
    public function close(Customer $customer, int $year, int $month): IncomeClosing
    {
        return IncomeClosing::updateOrCreate(
            ['customer_id' => $customer->id, 'year' => $year, 'month' => $month],
            ['closed_at' => Carbon::now()]
        );
    }

    public function reopen(Customer $customer, int $year, int $month): void
    {
        IncomeClosing::where('customer_id', $customer->id)->where('year', $year)->where('month', $month)->delete();
    }

    public function isClosed(Customer $customer, int $year, int $month): bool
    {
        return IncomeClosing::where('customer_id', $customer->id)->where('year', $year)->where('month', $month)->exists();
    }

    /** @return array<int, bool> month (1-12) => closed */
    public function closedMonths(Customer $customer, int $year): array
    {
        $closed = IncomeClosing::where('customer_id', $customer->id)->where('year', $year)->pluck('month')->all();

        return collect(range(1, 12))->mapWithKeys(fn ($m) => [$m => in_array($m, $closed, true)])->all();
    }
}
