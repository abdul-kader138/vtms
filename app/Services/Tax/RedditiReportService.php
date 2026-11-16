<?php

namespace App\Services\Tax;

use App\Enums\CustomerType;
use App\Models\Customer;
use App\Models\InpsCalculation;
use App\Models\TaxCalculation;
use RuntimeException;

/**
 * Draft figures for the "Redditi Persone Fisiche" return — Quadro LM
 * (forfettario business/professional income and the imposta sostitutiva)
 * and Quadro RR (INPS contributions self-declared by Artigiani/Commercianti
 * or Gestione Separata professionals). This is a worksheet to check figures
 * against an accountant's or RedditiOnline's own form before filing: the
 * real return can only be filed online or through an authorized
 * intermediary, never from this PDF, and line numbers must be checked
 * against the form in force for the filing year.
 */
class RedditiReportService
{
    public function build(Customer $customer, int $year): array
    {
        $tax = TaxCalculation::where('customer_id', $customer->id)->where('tax_year', $year)->first();

        if (! $tax) {
            throw new RuntimeException("No tax calculation found for customer #{$customer->id}, year {$year}.");
        }

        $inps = InpsCalculation::where('customer_id', $customer->id)->where('tax_year', $year)->first();

        return [
            'customer' => $customer,
            'year' => $year,
            'tax_calculation' => $tax,
            'inps_calculation' => $inps,
            'lm' => $this->quadroLm($customer, $tax),
            'rr' => $inps ? $this->quadroRr($customer, $inps) : null,
        ];
    }

    /**
     * Quadro LM, Sezione II (regime forfettario): revenue, the legal
     * coefficient, the resulting gross income, INPS contributions actually
     * paid in the year (deductible), taxable income, rate and tax due.
     *
     * @return array{total_sales: float, coefficient: float, gross_income: float, inps_deducted: float,
     *     taxable_income: float, tax_rate: float, tax_due: float, ateco_code: ?string}
     */
    private function quadroLm(Customer $customer, TaxCalculation $tax): array
    {
        return [
            'total_sales' => (float) $tax->total_sales,
            'coefficient' => (float) $tax->coefficient_used,
            'gross_income' => $tax->grossIncome(),
            'inps_deducted' => (float) $tax->inps_deducted,
            'taxable_income' => (float) $tax->taxable_income,
            'tax_rate' => (float) $tax->tax_rate_used,
            'tax_due' => (float) $tax->tax_due,
            'ateco_code' => $customer->ateco_code ?: $customer->inail_ateco_code,
        ];
    }

    /**
     * Quadro RR: Sezione I for Artigiani/Commercianti (fixed contributions on
     * the minimale plus the percentage on income above it), Sezione II for
     * Gestione Separata (a single percentage on the whole taxable income).
     *
     * @return array{section: string, taxable_income: float, rate: float, fixed_amount: ?float,
     *     variable_amount: ?float, discount_percentage: float, discount_amount: float, amount_due: float}
     */
    private function quadroRr(Customer $customer, InpsCalculation $inps): array
    {
        return [
            'section' => $customer->customer_type === CustomerType::GestioneSeparata ? 'II' : 'I',
            'taxable_income' => (float) $inps->taxable_income,
            'rate' => (float) $inps->inps_rate,
            'fixed_amount' => $inps->fixed_amount !== null ? (float) $inps->fixed_amount : null,
            'variable_amount' => $inps->variable_amount !== null ? (float) $inps->variable_amount : null,
            'discount_percentage' => (float) $inps->discount_percentage,
            'discount_amount' => (float) $inps->discount_amount,
            'amount_due' => (float) $inps->inps_amount_after_discount,
        ];
    }

    public function filename(Customer $customer, int $year): string
    {
        return 'Redditi_PF_'.preg_replace('/[^A-Za-z0-9_-]+/', '_', $customer->name).'_'.$year.'.pdf';
    }
}
