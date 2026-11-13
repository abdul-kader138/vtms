<?php

namespace App\Services\Tax;

use App\Enums\CustomerType;
use App\Enums\TaxPaymentType;
use App\Models\Customer;
use App\Models\DirittoAnnualeBracket;
use App\Models\DirittoAnnualePayment;
use App\Models\FatturaTraPrivatiCalculation;
use App\Models\FatturaTraPrivatiPayment;
use App\Models\InailCalculation;
use App\Models\InailPayment;
use App\Models\InpsCalculation;
use App\Models\InpsPayment;
use App\Models\TaxCalculation;
use App\Models\TaxPayment;
use Carbon\Carbon;

class PaymentScheduleService
{
    /**
     * Balance due Feb 16 (Y+1), skipped entirely in the customer's own
     * business-start year (confirmed VTMS behavior). Two advances toward
     * Y+1's liability (40% of this year's tax_due each), due Jun 30 and
     * Nov 30 (Y+1) — stored with tax_year = Y+1, the year they apply
     * toward, which is what TaxCalculationService::calculateForYear()
     * queries when it sums "advance_payments_made" for that year.
     */
    public function scheduleTaxPayments(TaxCalculation $tc, Customer $customer): void
    {
        $year = $tc->tax_year;
        $startYear = $customer->business_start_date?->year;

        if ($startYear !== $year) {
            TaxPayment::updateOrCreate(
                ['customer_id' => $customer->id, 'payment_type' => TaxPaymentType::Balance, 'tax_year' => $year],
                [
                    'tax_calculation_id' => $tc->id,
                    'amount' => $tc->balance_payment,
                    'due_date' => Carbon::create($year + 1, 2, 16),
                ]
            );
        }

        $advanceEach = round((float) $tc->tax_due * 0.40, 2);

        if ($advanceEach > 0) {
            TaxPayment::updateOrCreate(
                ['customer_id' => $customer->id, 'payment_type' => TaxPaymentType::FirstAdvance, 'tax_year' => $year + 1],
                [
                    'tax_calculation_id' => $tc->id,
                    'amount' => $advanceEach,
                    'due_date' => Carbon::create($year + 1, 6, 30),
                ]
            );

            TaxPayment::updateOrCreate(
                ['customer_id' => $customer->id, 'payment_type' => TaxPaymentType::SecondAdvance, 'tax_year' => $year + 1],
                [
                    'tax_calculation_id' => $tc->id,
                    'amount' => $advanceEach,
                    'due_date' => Carbon::create($year + 1, 11, 30),
                ]
            );
        }
    }

    public function scheduleInpsPayments(InpsCalculation $ic, Customer $customer): void
    {
        $year = $ic->tax_year;
        $amount = (float) $ic->inps_amount_after_discount;

        if ($customer->customer_type === CustomerType::GestioneSeparata) {
            $this->upsertInpsInstallment($customer, $ic, $year, 1, $amount, Carbon::create($year + 1, 6, 30));
            $this->upsertInpsInstallment($customer, $ic, $year, 2, round($amount * 0.40, 2), Carbon::create($year + 1, 6, 30));
            $this->upsertInpsInstallment($customer, $ic, $year, 3, round($amount * 0.40, 2), Carbon::create($year + 1, 11, 30));

            return;
        }

        // Commercianti/Artigiani: 4 equal installments. Any rounding
        // remainder is put on the last installment so the 4 amounts sum
        // EXACTLY to inps_amount_after_discount (confirmed decision: fix
        // VTMS's silent 1-cent drift rather than reproduce it).
        $base = round($amount / 4, 2);
        $dueDates = [
            Carbon::create($year, 5, 16),
            Carbon::create($year, 8, 20),
            Carbon::create($year, 11, 16),
            Carbon::create($year + 1, 2, 16),
        ];

        $runningTotal = 0.0;
        for ($i = 1; $i <= 4; $i++) {
            $installmentAmount = $i < 4 ? $base : round($amount - $runningTotal, 2);
            $runningTotal += $i < 4 ? $base : $installmentAmount;
            $this->upsertInpsInstallment($customer, $ic, $year, $i, $installmentAmount, $dueDates[$i - 1]);
        }
    }

    private function upsertInpsInstallment(Customer $customer, InpsCalculation $ic, int $year, int $installmentNumber, float $amount, Carbon $dueDate): void
    {
        InpsPayment::updateOrCreate(
            ['customer_id' => $customer->id, 'payment_year' => $year, 'installment_number' => $installmentNumber],
            [
                'inps_calculation_id' => $ic->id,
                'amount' => $amount,
                'due_date' => $dueDate,
            ]
        );
    }

    public function scheduleInailPayment(InailCalculation $ic, Customer $customer): void
    {
        $year = $ic->tax_year;

        InailPayment::updateOrCreate(
            ['customer_id' => $customer->id, 'payment_year' => $year],
            [
                'inail_calculation_id' => $ic->id,
                'amount' => $ic->inail_final_amount,
                'due_date' => Carbon::create($year + 1, 2, 16),
            ]
        );
    }

    /**
     * Due Mar 31 of the SAME year Y — the one exception to the Y+1 pattern
     * everything else in this module follows.
     */
    public function scheduleDirittoAnnualePayment(Customer $customer, int $year, DirittoAnnualeBracket $bracket): void
    {
        DirittoAnnualePayment::updateOrCreate(
            ['customer_id' => $customer->id, 'payment_year' => $year],
            [
                'diritto_annuale_bracket_id' => $bracket->id,
                'amount' => $bracket->amount,
                'due_date' => Carbon::create($year, 3, 31),
            ]
        );
    }

    public function scheduleFatturaTraPrivatiPayment(FatturaTraPrivatiCalculation $fc, Customer $customer): void
    {
        $year = $fc->tax_year;

        FatturaTraPrivatiPayment::updateOrCreate(
            ['customer_id' => $customer->id, 'payment_year' => $year],
            [
                'fattura_tra_privati_calculation_id' => $fc->id,
                'amount' => $fc->total_payment_amount,
                'due_date' => Carbon::create($year + 1, 2, 16),
            ]
        );
    }
}
