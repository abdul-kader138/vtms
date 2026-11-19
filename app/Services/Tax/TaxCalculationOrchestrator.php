<?php

namespace App\Services\Tax;

use App\Enums\TaxAdjustmentRequestStatus;
use App\Exceptions\Tax\ManualOverrideApprovalRequiredException;
use App\Models\Customer;
use App\Models\FatturaTraPrivatiCalculation;
use App\Models\InailCalculation;
use App\Models\InpsCalculation;
use App\Models\TaxAdjustmentRequest;
use App\Models\TaxCalculation;
use App\Models\TaxYearLock;
use App\Support\Tax\TaxRules;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class TaxCalculationOrchestrator
{
    /** A manual override this far off the computed value is flagged, even under the percent threshold (e.g. a €0 → €60 edit). */
    private const VARIANCE_ABSOLUTE_THRESHOLD = 50.0;

    /** A manual override this many percent off the computed value is flagged, even under the absolute threshold. */
    private const VARIANCE_PERCENT_THRESHOLD = 15.0;

    /** @var list<string> Populated by adjusted() during commit(), reset at the start of each commit(). */
    private array $varianceWarnings = [];

    public function __construct(
        private readonly TaxCalculationService $taxCalculationService,
        private readonly InpsCalculationService $inpsCalculationService,
        private readonly InailCalculationService $inailCalculationService,
        private readonly DirittoAnnualeCalculationService $dirittoAnnualeCalculationService,
        private readonly FatturaTraPrivatiCalculationService $fatturaTraPrivatiCalculationService,
        private readonly PaymentScheduleService $paymentScheduleService,
    ) {}

    /**
     * Runs all 5 calculators + their payment scheduling in one transaction,
     * with no staff overrides. Every step upserts keyed by (customer_id,
     * tax_year), so this is safe to re-run for the same customer/year (a
     * "Recalculate" action).
     */
    public function runForCustomerAndYear(Customer $customer, int $year): array
    {
        return $this->commit($customer, $year);
    }

    /**
     * Pure preview: runs every calculator's compute() step only — no
     * DB::transaction, no updateOrCreate, no payment scheduling. Lets a
     * caller (e.g. a Filament review modal) show the figures a recalculate
     * would produce before anything is persisted.
     *
     * @return array{tax: array<string, mixed>, inps: array<string, mixed>, inail: ?array<string, mixed>, fattura_tra_privati: ?array<string, mixed>}
     */
    public function preview(Customer $customer, int $year): array
    {
        $taxValues = $this->taxCalculationService->compute($customer, $year);
        // Unsaved instance: compute() for INPS/INAIL only read grossIncome()
        // off it, they never persist or requery it.
        $transientTax = new TaxCalculation($taxValues);

        return [
            'tax' => $taxValues,
            'inps' => $this->inpsCalculationService->compute($customer, $year, $transientTax),
            'inail' => $this->inailCalculationService->compute($customer, $year, $transientTax),
            'fattura_tra_privati' => $this->fatturaTraPrivatiCalculationService->compute($customer, $year),
        ];
    }

    /**
     * Runs all 5 calculators + their payment scheduling in one transaction,
     * applying any staff-supplied overrides on top of the freshly computed
     * figures before persisting. `$overrides` is keyed like preview()'s
     * result (`tax`, `inps`, `inail`, `fattura_tra_privati`); any field left
     * out keeps the computed value. A calculation is marked
     * is_manually_adjusted when a committed value differs from what was
     * just computed for it.
     *
     * @param  array<string, array<string, mixed>>  $overrides
     *
     * @throws \RuntimeException if the year is locked (see TaxYearLock), a
     *                           manual override is missing its required
     *                           adjustment_reason, or the current user
     *                           lacks the finalize_tax_calculation
     *                           permission needed to commit one.
     */
    public function commit(Customer $customer, int $year, array $overrides = []): array
    {
        if (TaxYearLock::where('customer_id', $customer->id)->where('tax_year', $year)->exists()) {
            throw new \RuntimeException(__(':year is locked for :customer and cannot be recalculated — unlock it first.', ['year' => $year, 'customer' => $customer->name]));
        }

        $this->varianceWarnings = [];

        return DB::transaction(function () use ($customer, $year, $overrides) {
            $taxValues = $this->adjusted($this->taxCalculationService->compute($customer, $year), $overrides['tax'] ?? [], __('Income tax'));
            $taxCalculation = TaxCalculation::updateOrCreate(['customer_id' => $customer->id, 'tax_year' => $year], $taxValues);
            $this->paymentScheduleService->scheduleTaxPayments($taxCalculation, $customer);

            $inpsValues = $this->adjusted($this->inpsCalculationService->compute($customer, $year, $taxCalculation), $overrides['inps'] ?? [], __('INPS'));
            $inpsCalculation = InpsCalculation::updateOrCreate(['customer_id' => $customer->id, 'tax_year' => $year], $inpsValues);
            $this->paymentScheduleService->scheduleInpsPayments($inpsCalculation, $customer);

            $inailComputed = $this->inailCalculationService->compute($customer, $year, $taxCalculation);
            $inailCalculation = null;
            if ($inailComputed !== null) {
                $inailCalculation = InailCalculation::updateOrCreate(
                    ['customer_id' => $customer->id, 'tax_year' => $year],
                    $this->adjusted($inailComputed, $overrides['inail'] ?? [], __('INAIL'))
                );
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

            $fatturaComputed = $this->fatturaTraPrivatiCalculationService->compute($customer, $year);
            $fatturaCalculation = null;
            if ($fatturaComputed !== null) {
                $fatturaCalculation = FatturaTraPrivatiCalculation::updateOrCreate(
                    ['customer_id' => $customer->id, 'tax_year' => $year],
                    $this->adjusted($fatturaComputed, $overrides['fattura_tra_privati'] ?? [], __('Imposta di bollo'))
                );
                $this->paymentScheduleService->scheduleFatturaTraPrivatiPayment($fatturaCalculation, $customer);
            }

            return [
                'tax' => $taxCalculation,
                'inps' => $inpsCalculation,
                'inail' => $inailCalculation,
                'diritto_annuale_bracket' => $bracket,
                'fattura_tra_privati' => $fatturaCalculation,
                'variance_warnings' => $this->varianceWarnings,
            ];
        });
    }

    /**
     * Merges staff overrides onto computed values, flagging
     * is_manually_adjusted/adjusted_by/adjusted_at when any overridden field
     * actually differs from what was just computed for it. A real
     * adjustment requires an adjustment_reason (maker-checker-lite: the
     * person finalizing it must also have the finalize_tax_calculation
     * permission) and, past VARIANCE_*_THRESHOLD, adds a entry to
     * $this->varianceWarnings for the caller to surface non-fatally.
     *
     * @param  array<string, mixed>  $computed
     * @param  array<string, mixed>  $override
     * @return array<string, mixed>
     */
    private function adjusted(array $computed, array $override, string $label): array
    {
        $isAdjusted = false;
        foreach ($override as $field => $value) {
            if ($field === 'adjustment_reason' || ! array_key_exists($field, $computed) || ! is_numeric($value) || ! is_numeric($computed[$field])) {
                continue;
            }

            if (round((float) $computed[$field], 2) !== round((float) $value, 2)) {
                $isAdjusted = true;
            }
        }

        if ($isAdjusted) {
            if (blank($override['adjustment_reason'] ?? null)) {
                throw new \RuntimeException(__(':label: a reason is required when overriding a computed figure.', ['label' => $label]));
            }

            if (! Auth::user()?->can('finalize_tax_calculation')) {
                throw new ManualOverrideApprovalRequiredException(__(':label: you don\'t have permission to finalize a manually-adjusted calculation — submit it for review instead.', ['label' => $label]));
            }

            $this->collectVarianceWarnings($computed, $override, $label);
        }

        return array_merge($computed, $override, [
            'is_manually_adjusted' => $isAdjusted,
            'adjusted_by' => $isAdjusted ? Auth::id() : null,
            'adjusted_at' => $isAdjusted ? now() : null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $computed
     * @param  array<string, mixed>  $override
     */
    private function collectVarianceWarnings(array $computed, array $override, string $label): void
    {
        foreach ($override as $field => $value) {
            if ($field === 'adjustment_reason' || ! array_key_exists($field, $computed) || ! is_numeric($value) || ! is_numeric($computed[$field])) {
                continue;
            }

            $computedValue = (float) $computed[$field];
            $overrideValue = (float) $value;
            $diff = abs($overrideValue - $computedValue);

            if ($diff < 0.01) {
                continue;
            }

            $percent = $computedValue !== 0.0 ? abs($diff / $computedValue) * 100 : null;

            if ($diff >= self::VARIANCE_ABSOLUTE_THRESHOLD || ($percent !== null && $percent >= self::VARIANCE_PERCENT_THRESHOLD)) {
                $this->varianceWarnings[] = __(':label — :field: computed € :computed, overridden to € :override (:percent).', [
                    'label' => $label,
                    'field' => $field,
                    'computed' => number_format($computedValue, 2, ',', '.'),
                    'override' => number_format($overrideValue, 2, ',', '.'),
                    'percent' => $percent !== null ? number_format($percent, 1).'%' : 'n/a',
                ]);
            }
        }
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
     * Approves a queued TaxAdjustmentRequest (see
     * ManualOverrideApprovalRequiredException) by re-running commit() with
     * its stored payload — under the approver's own identity, so
     * is_manually_adjusted/adjusted_by on the resulting calculation rows
     * reflect the approver, not the original requester. Propagates any
     * exception commit() throws (e.g. the year got locked meanwhile) and
     * leaves the request untouched in that case.
     */
    public function approveRequest(TaxAdjustmentRequest $request): array
    {
        $result = $this->commit($request->customer, $request->tax_year, $request->payload);

        $request->update([
            'status' => TaxAdjustmentRequestStatus::Approved,
            'reviewed_by' => Auth::id(),
            'reviewed_at' => now(),
        ]);

        return $result;
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
