<?php

namespace App\Services\Tax;

use App\Enums\TaxPaymentType;
use App\Models\Customer;
use App\Models\IncomeData;
use App\Models\TaxCalculation;
use App\Models\TaxPayment;

class TaxCalculationService
{
    /**
     * Regime Forfettario core calculation. Base = taxable_income directly;
     * INPS is never subtracted from it (confirmed VTMS behavior — a stale
     * DB column comment in the legacy app claimed otherwise, but the actual
     * calculation code never did, and that's what we replicate).
     */
    public function calculateForYear(Customer $customer, int $year): TaxCalculation
    {
        $totalSales = (float) IncomeData::where('customer_id', $customer->id)
            ->where('tax_year', $year)
            ->sum('taxable_sales');

        $coefficient = (float) $customer->coefficient_of_profitability;
        $taxRate = (float) $customer->tax_rate;

        $taxableIncome = round($totalSales * $coefficient / 100, 2);
        $taxDue = round($taxableIncome * $taxRate / 100, 2);

        // Advances are stored against the YEAR THEY APPLY TOWARD (Y+1 of the
        // calculation that scheduled them), not the year they were scheduled
        // from — see PaymentScheduleService::scheduleTaxPayments().
        $advancePaymentsMade = (float) TaxPayment::where('customer_id', $customer->id)
            ->where('tax_year', $year)
            ->whereIn('payment_type', [TaxPaymentType::FirstAdvance, TaxPaymentType::SecondAdvance])
            ->sum('amount');

        $balancePayment = max(0, round($taxDue - $advancePaymentsMade, 2));
        $nextYearAdvanceBase = round($taxDue * 0.80, 2);

        return TaxCalculation::updateOrCreate(
            ['customer_id' => $customer->id, 'tax_year' => $year],
            [
                'total_sales' => $totalSales,
                'taxable_income' => $taxableIncome,
                'coefficient_used' => $coefficient,
                'tax_rate_used' => $taxRate,
                'tax_due' => $taxDue,
                'advance_payments_made' => $advancePaymentsMade,
                'balance_payment' => $balancePayment,
                'next_year_advance_base' => $nextYearAdvanceBase,
            ]
        );
    }
}
