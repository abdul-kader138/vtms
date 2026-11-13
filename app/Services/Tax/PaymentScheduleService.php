<?php

namespace App\Services\Tax;

use App\Enums\CustomerType;
use App\Enums\InpsCalculationMethod;
use App\Enums\PaymentStatus;
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
use App\Support\Tax\ItalianCalendar;
use App\Support\Tax\TaxRules;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

class PaymentScheduleService
{
    /**
     * Legacy: balance due 16 Feb (Y+1), skipped in the business-start year;
     * two advances of 40% of tax due each, 30 Jun and 30 Nov (Y+1).
     *
     * Law: balance (code 1792) due 30 Jun (Y+1) in every year, including
     * the start year — it's the whole tax then, since no advances were due.
     * Advances total 100% of tax due: none at or under €51.65, a single
     * payment on 30 Nov under €257.52, otherwise 40% on 30 Jun and 60% on
     * 30 Nov. Deadlines move off weekends and holidays.
     *
     * Advances are stored with tax_year = Y+1, the year they count toward,
     * which is what TaxCalculationService sums as "advance_payments_made".
     */
    public function scheduleTaxPayments(TaxCalculation $tc, Customer $customer): void
    {
        $year = $tc->tax_year;
        $next = $year + 1;
        $taxDue = (float) $tc->tax_due;

        if (TaxRules::isLaw()) {
            $balanceDue = $this->deadline($next, 6, 30);
            $advances = match (true) {
                $taxDue <= TaxRules::ADVANCE_MINIMUM => [],
                $taxDue < TaxRules::ADVANCE_SINGLE_PAYMENT_LIMIT => [
                    TaxPaymentType::SecondAdvance->value => [round($taxDue, 2), $this->deadline($next, 11, 30)],
                ],
                default => [
                    TaxPaymentType::FirstAdvance->value => [round($taxDue * 0.40, 2), $this->deadline($next, 6, 30)],
                    TaxPaymentType::SecondAdvance->value => [round($taxDue - round($taxDue * 0.40, 2), 2), $this->deadline($next, 11, 30)],
                ],
            };
            $scheduleBalance = (float) $tc->balance_payment > 0;
        } else {
            $balanceDue = Carbon::create($next, 2, 16);
            $each = round($taxDue * 0.40, 2);
            $advances = $each > 0 ? [
                TaxPaymentType::FirstAdvance->value => [$each, Carbon::create($next, 6, 30)],
                TaxPaymentType::SecondAdvance->value => [$each, Carbon::create($next, 11, 30)],
            ] : [];
            $scheduleBalance = $customer->business_start_date?->year !== $year;
        }

        if ($scheduleBalance) {
            $this->upsert(TaxPayment::class,
                ['customer_id' => $customer->id, 'payment_type' => TaxPaymentType::Balance, 'tax_year' => $year],
                ['tax_calculation_id' => $tc->id, 'amount' => $tc->balance_payment, 'due_date' => $balanceDue]
            );
        } else {
            $this->untouched(TaxPayment::where('customer_id', $customer->id)->where('payment_type', TaxPaymentType::Balance)->where('tax_year', $year))->delete();
        }

        foreach ([TaxPaymentType::FirstAdvance, TaxPaymentType::SecondAdvance] as $type) {
            if (isset($advances[$type->value])) {
                [$amount, $due] = $advances[$type->value];
                $this->upsert(TaxPayment::class,
                    ['customer_id' => $customer->id, 'payment_type' => $type, 'tax_year' => $next],
                    ['tax_calculation_id' => $tc->id, 'amount' => $amount, 'due_date' => $due]
                );
            } else {
                $this->untouched(TaxPayment::where('customer_id', $customer->id)->where('payment_type', $type)->where('tax_year', $next))->delete();
            }
        }
    }

    /** Installments for income above the minimale (law rules, Artigiani/Commercianti). */
    public const INPS_EXCESS_BALANCE = 5;

    public const INPS_EXCESS_FIRST_ADVANCE = 6;

    public const INPS_EXCESS_SECOND_ADVANCE = 7;

    public function scheduleInpsPayments(InpsCalculation $ic, Customer $customer): void
    {
        $year = $ic->tax_year;
        $amount = (float) $ic->inps_amount_after_discount;
        $date = fn (int $y, int $m, int $d) => $this->deadline($y, $m, $d);

        if ($ic->calculation_method === InpsCalculationMethod::FixedPlusPercentage) {
            $this->scheduleFixedPlusPercentage($ic, $customer);

            return;
        }

        // Excess-income installments only exist under the law method.
        $this->untouched(InpsPayment::where('customer_id', $customer->id)->where('payment_year', $year)->where('installment_number', self::INPS_EXCESS_BALANCE))->delete();
        $this->untouched(InpsPayment::where('customer_id', $customer->id)->where('payment_year', $year + 1)->whereIn('installment_number', [self::INPS_EXCESS_FIRST_ADVANCE, self::INPS_EXCESS_SECOND_ADVANCE]))->delete();

        if ($customer->customer_type === CustomerType::GestioneSeparata) {
            $this->upsertInpsInstallment($customer, $ic, $year, 1, $amount, $date($year + 1, 6, 30));
            $this->upsertInpsInstallment($customer, $ic, $year, 2, round($amount * 0.40, 2), $date($year + 1, 6, 30));
            $this->upsertInpsInstallment($customer, $ic, $year, 3, round($amount * 0.40, 2), $date($year + 1, 11, 30));

            return;
        }

        // Commercianti/Artigiani: 4 equal installments. Any rounding
        // remainder is put on the last installment so the 4 amounts sum
        // EXACTLY to inps_amount_after_discount (confirmed decision: fix
        // VTMS's silent 1-cent drift rather than reproduce it).
        $base = round($amount / 4, 2);
        $dueDates = [
            $date($year, 5, 16),
            $date($year, 8, 20),
            $date($year, 11, 16),
            $date($year + 1, 2, 16),
        ];

        $runningTotal = 0.0;
        for ($i = 1; $i <= 4; $i++) {
            $installmentAmount = $i < 4 ? $base : round($amount - $runningTotal, 2);
            $runningTotal += $i < 4 ? $base : $installmentAmount;
            $this->upsertInpsInstallment($customer, $ic, $year, $i, $installmentAmount, $dueDates[$i - 1]);
        }
    }

    /**
     * Fixed contributions: 4 equal installments (16 May, 20 Aug, 16 Nov of Y,
     * 16 Feb of Y+1), code AF/CF.
     * Income above the minimale (code AP/CP), paid with the income tax:
     * balance on 30 Jun of Y+1 = amount due − advances scheduled toward Y;
     * advances toward Y+1 = 100% of it, two halves on 30 Jun and 30 Nov.
     */
    private function scheduleFixedPlusPercentage(InpsCalculation $ic, Customer $customer): void
    {
        $year = $ic->tax_year;
        $next = $year + 1;
        ['fixed' => $fixed, 'variable' => $variable] = InpsCalculationService::splitAfterDiscount($ic);

        $base = round($fixed / 4, 2);
        $dueDates = [$this->deadline($year, 5, 16), $this->deadline($year, 8, 20), $this->deadline($year, 11, 16), $this->deadline($next, 2, 16)];
        for ($i = 1; $i <= 4; $i++) {
            $this->upsertInpsInstallment($customer, $ic, $year, $i, $i < 4 ? $base : round($fixed - 3 * $base, 2), $dueDates[$i - 1]);
        }

        $advancesTowardYear = (float) InpsPayment::where('customer_id', $customer->id)->where('payment_year', $year)
            ->whereIn('installment_number', [self::INPS_EXCESS_FIRST_ADVANCE, self::INPS_EXCESS_SECOND_ADVANCE])
            ->where('status', '!=', PaymentStatus::Cancelled)
            ->sum('amount');
        $balance = round($variable - $advancesTowardYear, 2);

        $this->scheduleOrRemoveInps($customer, $ic, $year, self::INPS_EXCESS_BALANCE, $balance, $this->deadline($next, 6, 30));

        $first = round($variable / 2, 2);
        $this->scheduleOrRemoveInps($customer, $ic, $next, self::INPS_EXCESS_FIRST_ADVANCE, $first, $this->deadline($next, 6, 30));
        $this->scheduleOrRemoveInps($customer, $ic, $next, self::INPS_EXCESS_SECOND_ADVANCE, round($variable - $first, 2), $this->deadline($next, 11, 30));
    }

    private function scheduleOrRemoveInps(Customer $customer, InpsCalculation $ic, int $paymentYear, int $installment, float $amount, CarbonInterface $due): void
    {
        if ($amount > 0) {
            $this->upsertInpsInstallment($customer, $ic, $paymentYear, $installment, $amount, $due);

            return;
        }

        $this->untouched(InpsPayment::where('customer_id', $customer->id)->where('payment_year', $paymentYear)->where('installment_number', $installment))->delete();
    }

    private function upsertInpsInstallment(Customer $customer, InpsCalculation $ic, int $year, int $installmentNumber, float $amount, CarbonInterface $dueDate): void
    {
        $this->upsert(InpsPayment::class,
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

        $this->upsert(InailPayment::class,
            ['customer_id' => $customer->id, 'payment_year' => $year],
            [
                'inail_calculation_id' => $ic->id,
                'amount' => $ic->inail_final_amount,
                'due_date' => $this->deadline($year + 1, 2, 16),
            ]
        );
    }

    /**
     * Legacy: due 31 Mar of the same year Y (bracket amount).
     * Law: due with the income-tax balance, 30 Jun of Y (code 3850) — except
     * the year the business registers, when it is paid within 30 days of
     * registration.
     */
    public function scheduleDirittoAnnualePayment(Customer $customer, int $year, ?DirittoAnnualeBracket $bracket, ?float $amount = null): void
    {
        $start = $customer->business_start_date;
        $due = match (true) {
            ! TaxRules::isLaw() => Carbon::create($year, 3, 31),
            $start !== null && $start->year === $year => ItalianCalendar::nextBusinessDay($start->copy()->addDays(30)),
            default => $this->deadline($year, 6, 30),
        };

        $this->upsert(DirittoAnnualePayment::class,
            ['customer_id' => $customer->id, 'payment_year' => $year],
            [
                'diritto_annuale_bracket_id' => $bracket?->id,
                'amount' => $amount ?? $bracket->amount,
                'due_date' => $due,
            ]
        );
    }

    /**
     * Legacy: one payment for the year, 16 Feb (Y+1).
     *
     * Law: one payment per quarter (codes 2521–2524), due 31 May, 30 Sep,
     * 30 Nov and the last day of February (Y+1). Q1 moves to Q2's deadline
     * while it is ≤ €5,000, and Q1+Q2 move to Q3's while together ≤ €5,000.
     */
    public function scheduleFatturaTraPrivatiPayment(FatturaTraPrivatiCalculation $fc, Customer $customer): void
    {
        $year = $fc->tax_year;

        if (! TaxRules::isLaw()) {
            $this->untouched(FatturaTraPrivatiPayment::where('customer_id', $customer->id)->where('payment_year', $year)->where('quarter', '>', 0))->delete();

            $this->upsert(FatturaTraPrivatiPayment::class,
                ['customer_id' => $customer->id, 'payment_year' => $year, 'quarter' => 0],
                [
                    'fattura_tra_privati_calculation_id' => $fc->id,
                    'amount' => $fc->total_payment_amount,
                    'due_date' => Carbon::create($year + 1, 2, 16),
                ]
            );

            return;
        }

        $this->untouched(FatturaTraPrivatiPayment::where('customer_id', $customer->id)->where('payment_year', $year)->where('quarter', 0))->delete();

        $amounts = $this->quarterlyStampDuty($customer, $year, (float) $fc->minimum_invoice_amount, (float) $fc->payment_per_invoice);
        $limit = TaxRules::STAMP_DUTY_DEFERRAL_LIMIT;

        $dueDates = [
            1 => $this->deadline($year, 5, 31),
            2 => $this->deadline($year, 9, 30),
            3 => $this->deadline($year, 11, 30),
            4 => ItalianCalendar::nextBusinessDay(Carbon::create($year + 1, 2, 1)->endOfMonth()),
        ];

        if ($amounts[1] <= $limit) {
            $dueDates[1] = $dueDates[2];
        }
        if ($limit >= $amounts[1] + $amounts[2]) {
            $dueDates[1] = $dueDates[2] = $dueDates[3];
        }

        foreach ($amounts as $quarter => $amount) {
            $query = FatturaTraPrivatiPayment::where('customer_id', $customer->id)->where('payment_year', $year)->where('quarter', $quarter);

            if ($amount <= 0) {
                $this->untouched($query)->delete();

                continue;
            }

            $this->upsert(FatturaTraPrivatiPayment::class,
                ['customer_id' => $customer->id, 'payment_year' => $year, 'quarter' => $quarter],
                [
                    'fattura_tra_privati_calculation_id' => $fc->id,
                    'amount' => $amount,
                    'due_date' => $dueDates[$quarter],
                ]
            );
        }
    }

    /** @return array<int, float> quarter => stamp duty owed */
    private function quarterlyStampDuty(Customer $customer, int $year, float $threshold, float $fee): array
    {
        $amounts = [1 => 0.0, 2 => 0.0, 3 => 0.0, 4 => 0.0];

        FatturaTraPrivatiCalculationService::qualifying($customer->id, $year, $threshold)
            ->pluck('transaction_date')
            ->each(function ($date) use (&$amounts, $fee) {
                $amounts[Carbon::parse($date)->quarter] += $fee;
            });

        return array_map(fn ($v) => round($v, 2), $amounts);
    }

    /**
     * updateOrCreate that never touches a payment someone already settled
     * (paid or cancelled): a recalculation — or switching rule sets — must
     * not rewrite the amount or due date of a recorded payment.
     */
    private function upsert(string $model, array $keys, array $values): void
    {
        $existing = $model::where($keys)->first();

        if ($existing && in_array($existing->status, [PaymentStatus::Paid, PaymentStatus::Cancelled], true)) {
            return;
        }

        $model::updateOrCreate($keys, $values);
    }

    private function deadline(int $year, int $month, int $day): CarbonInterface
    {
        return TaxRules::isLaw()
            ? ItalianCalendar::deadline($year, $month, $day)
            : Carbon::create($year, $month, $day);
    }

    /**
     * Rows a person never touched — safe to remove when a recalculation no
     * longer produces them. Anything paid, part-paid, cancelled or with a
     * receipt is kept.
     */
    private function untouched(Builder $query): Builder
    {
        return $query->whereIn('status', [PaymentStatus::Pending, PaymentStatus::Overdue])
            ->where('paid_amount', 0)
            ->whereNull('uploaded_pdf');
    }
}
