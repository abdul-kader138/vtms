<?php

namespace App\Services\Tax;

use App\Models\Customer;
use App\Models\InpsCalculation;
use App\Models\TaxCalculation;
use App\Support\Tax\TaxRules;
use Illuminate\Support\Facades\DB;

class TaxCalculationOrchestrator
{
    public function __construct(
        private readonly TaxCalculationService $taxCalculationService,
        private readonly InpsCalculationService $inpsCalculationService,
        private readonly InailCalculationService $inailCalculationService,
        private readonly DirittoAnnualeCalculationService $dirittoAnnualeCalculationService,
        private readonly FatturaTraPrivatiCalculationService $fatturaTraPrivatiCalculationService,
        private readonly PaymentScheduleService $paymentScheduleService,
    ) {}

    /**
     * Runs all 5 calculators + their payment scheduling in one transaction.
     * Every step upserts keyed by (customer_id, tax_year), so this is safe
     * to re-run for the same customer/year (a "Recalculate" action).
     */
    public function runForCustomerAndYear(Customer $customer, int $year): array
    {
        return DB::transaction(function () use ($customer, $year) {
            $taxCalculation = $this->taxCalculationService->calculateForYear($customer, $year);
            $this->paymentScheduleService->scheduleTaxPayments($taxCalculation, $customer);

            $inpsCalculation = $this->inpsCalculationService->calculateForYear($customer, $year, $taxCalculation);
            $this->paymentScheduleService->scheduleInpsPayments($inpsCalculation, $customer);

            $inailCalculation = $this->inailCalculationService->calculateForYear($customer, $year, $taxCalculation);
            if ($inailCalculation) {
                $this->paymentScheduleService->scheduleInailPayment($inailCalculation, $customer);
            }

            $bracket = null;
            if (TaxRules::isLaw()) {
                $amount = $this->dirittoAnnualeCalculationService->amountFor($customer);
                if ($amount) {
                    $this->paymentScheduleService->scheduleDirittoAnnualePayment($customer, $year, null, $amount);
                }
            } else {
                $bracket = $this->dirittoAnnualeCalculationService->matchBracket($customer, $year, $taxCalculation);
                if ($bracket) {
                    $this->paymentScheduleService->scheduleDirittoAnnualePayment($customer, $year, $bracket);
                }
            }

            $fatturaCalculation = $this->fatturaTraPrivatiCalculationService->calculateForYear($customer, $year);
            if ($fatturaCalculation) {
                $this->paymentScheduleService->scheduleFatturaTraPrivatiPayment($fatturaCalculation, $customer);
            }

            return [
                'tax' => $taxCalculation,
                'inps' => $inpsCalculation,
                'inail' => $inailCalculation,
                'diritto_annuale_bracket' => $bracket,
                'fattura_tra_privati' => $fatturaCalculation,
            ];
        });
    }

    /**
     * Recalculates $startYear and every following year that already has a
     * calculation, in order, stopping at the first year with neither a
     * TaxCalculation nor an InpsCalculation row (nothing further to walk).
     *
     * Safe to chain like this because runForCustomerAndYear() never
     * rewrites a paid/cancelled payment (PaymentScheduleService::upsert())
     * — only the parent calculation row and any still-pending payment are
     * ever touched. Useful after editing or deleting an earlier year
     * (TaxYearDeletionService), since one year's advances/excess-income
     * installments are scheduled forward into the next (see
     * PaymentScheduleService::scheduleTaxPayments() / scheduleFixedPlusPercentage()),
     * so a change doesn't stop at year + 1.
     *
     * @return list<array{year: int, before: array<string, ?float>, after: array<string, ?float>, changed: bool}>
     */
    public function runForwardFromYear(Customer $customer, int $startYear, int $maxYears = 25): array
    {
        $results = [];
        $year = $startYear;

        for ($i = 0; $i < $maxYears; $i++, $year++) {
            if (! $this->hasData($customer, $year)) {
                break;
            }

            $before = $this->snapshot($customer, $year);
            $this->runForCustomerAndYear($customer->fresh(), $year);
            $after = $this->snapshot($customer, $year);

            $results[] = ['year' => $year, 'before' => $before, 'after' => $after, 'changed' => $before !== $after];
        }

        return $results;
    }

    /**
     * TaxRules is a single global toggle (Setting::get('tax_rules')), not
     * per customer — but each TaxCalculation row remembers which rule set
     * it was actually computed under ('rules' column). If the global
     * toggle has since changed (or was only ever flipped temporarily for
     * one batch job — see ClientSolimanCommerciantiSeeder /
     * DemoTaxDataSeeder for examples of that pattern), a plain recalculate
     * would silently switch methodology for this one customer/year and
     * overwrite real figures with ones computed a different way. Returns
     * the year's stored rule set if it no longer matches the current
     * global one, so a caller can refuse or warn instead of recalculating
     * blind — null means there's nothing stored yet, or it already matches.
     */
    public function ruleMismatch(Customer $customer, int $year): ?string
    {
        $stored = TaxCalculation::where('customer_id', $customer->id)->where('tax_year', $year)->value('rules');
        $current = TaxRules::mode();

        return ($stored !== null && $stored !== $current) ? $stored : null;
    }

    private function hasData(Customer $customer, int $year): bool
    {
        return TaxCalculation::where('customer_id', $customer->id)->where('tax_year', $year)->exists()
            || InpsCalculation::where('customer_id', $customer->id)->where('tax_year', $year)->exists();
    }

    /** @return array<string, ?float> */
    private function snapshot(Customer $customer, int $year): array
    {
        $tc = TaxCalculation::where('customer_id', $customer->id)->where('tax_year', $year)->first();
        $ic = InpsCalculation::where('customer_id', $customer->id)->where('tax_year', $year)->first();

        return [
            'tax_due' => $tc ? (float) $tc->tax_due : null,
            'advance_payments_made' => $tc ? (float) $tc->advance_payments_made : null,
            'balance_payment' => $tc ? (float) $tc->balance_payment : null,
            'inps_amount_after_discount' => $ic ? (float) $ic->inps_amount_after_discount : null,
        ];
    }
}
