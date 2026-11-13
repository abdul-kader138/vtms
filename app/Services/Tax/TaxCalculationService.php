<?php

namespace App\Services\Tax;

use App\Enums\TaxPaymentType;
use App\Models\Customer;
use App\Models\IncomeData;
use App\Models\InpsPayment;
use App\Models\TaxCalculation;
use App\Models\TaxPayment;
use App\Support\Tax\TaxRules;

class TaxCalculationService
{
    /**
     * Regime Forfettario core calculation.
     *
     * Legacy rules: base = sales × coefficient, INPS never subtracted (the
     * original VTMS behavior), next-year advance base = 80% of tax due.
     *
     * Law rules: mandatory INPS contributions actually paid during the year
     * (cash basis, by paid_date) are deducted from the forfettario income
     * (L. 190/2014, c. 64), and the advance base is 100% of tax due.
     */
    public function calculateForYear(Customer $customer, int $year): TaxCalculation
    {
        $law = TaxRules::isLaw();

        // Law: cash basis — what was collected in the year. Legacy: what was
        // invoiced in the year (VTMS).
        $totalSales = (float) IncomeData::where('customer_id', $customer->id)
            ->revenueForYear($year)
            ->sum('taxable_sales');

        $coefficient = (float) $customer->coefficient_of_profitability;
        $taxRate = $customer->taxRateFor($year);

        $grossIncome = round($totalSales * $coefficient / 100, 2);

        $inpsDeducted = $law
            ? round(min($grossIncome, (float) InpsPayment::where('customer_id', $customer->id)
                ->whereYear('paid_date', $year)
                ->sum('paid_amount')), 2)
            : 0.0;

        $taxableIncome = round(max(0, $grossIncome - $inpsDeducted), 2);
        $taxDue = round($taxableIncome * $taxRate / 100, 2);

        // Advances are stored against the YEAR THEY APPLY TOWARD (Y+1 of the
        // calculation that scheduled them), not the year they were scheduled
        // from — see PaymentScheduleService::scheduleTaxPayments().
        $advancePaymentsMade = (float) TaxPayment::where('customer_id', $customer->id)
            ->where('tax_year', $year)
            ->whereIn('payment_type', [TaxPaymentType::FirstAdvance, TaxPaymentType::SecondAdvance])
            ->sum('amount');

        $balancePayment = max(0, round($taxDue - $advancePaymentsMade, 2));
        $nextYearAdvanceBase = round($taxDue * ($law ? 1.00 : 0.80), 2);

        return TaxCalculation::updateOrCreate(
            ['customer_id' => $customer->id, 'tax_year' => $year],
            [
                'total_sales' => $totalSales,
                'gross_income' => $grossIncome,
                'inps_deducted' => $inpsDeducted,
                'taxable_income' => $taxableIncome,
                'coefficient_used' => $coefficient,
                'tax_rate_used' => $taxRate,
                'tax_due' => $taxDue,
                'advance_payments_made' => $advancePaymentsMade,
                'balance_payment' => $balancePayment,
                'next_year_advance_base' => $nextYearAdvanceBase,
                'rules' => $law ? TaxRules::LAW : TaxRules::LEGACY,
            ]
        );
    }
}
