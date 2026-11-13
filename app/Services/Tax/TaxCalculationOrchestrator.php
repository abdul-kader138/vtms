<?php

namespace App\Services\Tax;

use App\Models\Customer;
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
}
