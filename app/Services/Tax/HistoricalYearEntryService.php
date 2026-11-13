<?php

namespace App\Services\Tax;

use App\Models\Customer;
use App\Models\DirittoAnnualePayment;
use App\Models\FatturaTraPrivatiCalculation;
use App\Models\FatturaTraPrivatiPayment;
use App\Models\InailCalculation;
use App\Models\InailPayment;
use App\Models\InpsCalculation;
use App\Models\InpsPayment;
use App\Models\TaxCalculation;
use App\Models\TaxPayment;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class HistoricalYearEntryService
{
    /**
     * Manual backfill for a prior year — writes directly into the same
     * tables the automatic calculators use, bypassing all calculation
     * logic. Mirrors VTMS's "historical year" admin form: an admin types in
     * every number by hand rather than recomputing from income_data.
     *
     * Expected $data shape (every top-level key optional except none are
     * required structurally, but at minimum 'tax' is expected for the form
     * to make sense):
     *
     * [
     *   'tax' => ['total_sales','taxable_income','tax_due','advance_payments_made','balance_payment','next_year_advance_base'],
     *   'tax_payments' => [['payment_type','amount','due_date','status'?,'paid_amount'?,'paid_date'?,'uploaded_pdf'?], ...],
     *   'inps' => ['inps_amount','inps_rate','discount_percentage','discount_amount','inps_amount_after_discount','calculation_method','slab_details'?],
     *   'inps_payments' => [['installment_number','amount','due_date', ...status/paid fields], ...],
     *   'inail' => ['inail_rate','inail_calculated_amount','inail_minimum_payment','inail_final_amount','ateco_code'?],
     *   'inail_payment' => ['amount','due_date', ...status/paid fields],
     *   'diritto_annuale_payment' => ['amount','due_date','diritto_annuale_bracket_id'?, ...status/paid fields],
     *   'fattura_tra_privati' => ['total_invoices','total_sales_amount','payment_per_invoice','total_payment_amount','minimum_invoice_amount'],
     *   'fattura_tra_privati_payment' => ['amount','due_date', ...status/paid fields],
     * ]
     */
    public function save(Customer $customer, int $year, array $data): void
    {
        if ($customer->customer_type === null || $customer->coefficient_of_profitability === null || $customer->tax_rate === null) {
            throw new RuntimeException('Customer must have customer_type, coefficient_of_profitability, and tax_rate configured before entering historical tax data.');
        }

        DB::transaction(function () use ($customer, $year, $data) {
            $taxCalculation = null;

            if (isset($data['tax'])) {
                $taxCalculation = TaxCalculation::updateOrCreate(
                    ['customer_id' => $customer->id, 'tax_year' => $year],
                    $data['tax'] + ['coefficient_used' => $customer->coefficient_of_profitability, 'tax_rate_used' => $customer->tax_rate]
                );
            }

            foreach ($data['tax_payments'] ?? [] as $payment) {
                TaxPayment::updateOrCreate(
                    ['customer_id' => $customer->id, 'payment_type' => $payment['payment_type'], 'tax_year' => $payment['tax_year'] ?? $year],
                    array_merge($payment, ['tax_calculation_id' => $taxCalculation?->id])
                );
            }

            $inpsCalculation = null;

            if (isset($data['inps'])) {
                $inpsCalculation = InpsCalculation::updateOrCreate(
                    ['customer_id' => $customer->id, 'tax_year' => $year],
                    $data['inps']
                );
            }

            foreach ($data['inps_payments'] ?? [] as $payment) {
                InpsPayment::updateOrCreate(
                    ['customer_id' => $customer->id, 'payment_year' => $year, 'installment_number' => $payment['installment_number']],
                    array_merge($payment, ['inps_calculation_id' => $inpsCalculation?->id])
                );
            }

            $inailCalculation = null;

            if (isset($data['inail'])) {
                $inailCalculation = InailCalculation::updateOrCreate(
                    ['customer_id' => $customer->id, 'tax_year' => $year],
                    $data['inail']
                );
            }

            if (isset($data['inail_payment'])) {
                InailPayment::updateOrCreate(
                    ['customer_id' => $customer->id, 'payment_year' => $year],
                    array_merge($data['inail_payment'], ['inail_calculation_id' => $inailCalculation?->id])
                );
            }

            if (isset($data['diritto_annuale_payment'])) {
                DirittoAnnualePayment::updateOrCreate(
                    ['customer_id' => $customer->id, 'payment_year' => $year],
                    $data['diritto_annuale_payment']
                );
            }

            $fatturaCalculation = null;

            if (isset($data['fattura_tra_privati'])) {
                $fatturaCalculation = FatturaTraPrivatiCalculation::updateOrCreate(
                    ['customer_id' => $customer->id, 'tax_year' => $year],
                    $data['fattura_tra_privati']
                );
            }

            if (isset($data['fattura_tra_privati_payment'])) {
                FatturaTraPrivatiPayment::updateOrCreate(
                    ['customer_id' => $customer->id, 'payment_year' => $year],
                    array_merge($data['fattura_tra_privati_payment'], ['fattura_tra_privati_calculation_id' => $fatturaCalculation?->id])
                );
            }
        });
    }
}
