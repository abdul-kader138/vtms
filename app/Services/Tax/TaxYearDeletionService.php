<?php

namespace App\Services\Tax;

use App\Enums\PaymentStatus;
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
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Deleting a tax year is not a plain row delete: every migration FK from a
 * payment table to its calculation table is cascadeOnDelete (see
 * create_tax_payments_table, create_inps_payments_table, ...), and two
 * payment rows reach INTO THE NEXT YEAR while still belonging to THIS
 * year's calculation row:
 *
 *  - tax_payments: the two advances due in Y+1 carry tax_calculation_id of
 *    year Y's TaxCalculation, because they were scheduled FROM year Y's tax
 *    due (PaymentScheduleService::scheduleTaxPayments — "Advances are
 *    stored against the YEAR THEY APPLY TOWARD... not the year they were
 *    scheduled from").
 *  - inps_payments: for Artigiani/Commercianti, the "excess income"
 *    advances (installment 6/7, due in Y+1) carry inps_calculation_id of
 *    year Y's InpsCalculation (PaymentScheduleService::scheduleFixedPlusPercentage).
 *
 * A naive `TaxCalculation::find($id)->delete()` would silently cascade
 * into — and destroy — a payment that was actually paid, just because it's
 * reachable from this year's calculation row. This service finds every
 * payment that WOULD cascade (via each calculation's payments() relation,
 * which already includes those forward-reaching rows) and refuses the
 * whole operation if any of them has ever been touched: paid, cancelled,
 * part-paid, has an uploaded receipt, or the customer self-reported paying
 * it. Nothing is ever force-deleted or auto-unlinked — the caller has to
 * resolve those payments first (e.g. unmark them as paid) if they really
 * want the year gone.
 *
 * Deleting also leaves the NEXT year's own stored figures stale — e.g.
 * next year's TaxCalculation.advance_payments_made summed the rows this
 * just removed — so the caller should recalculate year + 1 afterwards if
 * it has any data. staleYear() tells them whether that's worth doing.
 */
class TaxYearDeletionService
{
    /**
     * @return array{
     *     has_data: bool,
     *     deletable: bool,
     *     counts: array<string, int>,
     *     blockers: list<array{type: string, id: int, year: int, amount: float, paid_amount: float, paid_date: ?string, status: string, reported: bool, belongs_to_next_year: bool}>,
     * }
     */
    public function preview(Customer $customer, int $year): array
    {
        $taxCalculation = TaxCalculation::where('customer_id', $customer->id)->where('tax_year', $year)->first();
        $inpsCalculation = InpsCalculation::where('customer_id', $customer->id)->where('tax_year', $year)->first();
        $inailCalculation = InailCalculation::where('customer_id', $customer->id)->where('tax_year', $year)->first();
        $fatturaCalculation = FatturaTraPrivatiCalculation::where('customer_id', $customer->id)->where('tax_year', $year)->first();
        $dirittoAnnuale = DirittoAnnualePayment::where('customer_id', $customer->id)->where('payment_year', $year)->first();

        $payments = collect()
            ->merge($taxCalculation?->payments ?? [])
            ->merge($inpsCalculation?->payments ?? [])
            ->merge($inailCalculation?->payments ?? [])
            ->merge($fatturaCalculation?->payments ?? [])
            ->when($dirittoAnnuale, fn ($c) => $c->push($dirittoAnnuale));

        $blockers = $payments->filter($this->isTouched(...))
            ->map(function (TaxPayment|InpsPayment|InailPayment|FatturaTraPrivatiPayment|DirittoAnnualePayment $p) use ($year) {
                $paymentYear = (int) ($p->tax_year ?? $p->payment_year);

                return [
                    'type' => Str::headline(class_basename($p)),
                    'id' => $p->id,
                    'year' => $paymentYear,
                    'amount' => (float) $p->amount,
                    'paid_amount' => (float) $p->paid_amount,
                    'paid_date' => $p->paid_date?->toDateString(),
                    'status' => $p->status->value,
                    'reported' => $p->reported_paid_at !== null,
                    // Only tax_payments/inps_payments can have this be true
                    // — see the class docblock.
                    'belongs_to_next_year' => $paymentYear === $year + 1,
                ];
            })
            ->values()
            ->all();

        return [
            'has_data' => $taxCalculation || $inpsCalculation || $inailCalculation || $fatturaCalculation || $dirittoAnnuale,
            'deletable' => $blockers === [],
            'counts' => [
                'tax_calculation' => $taxCalculation ? 1 : 0,
                'inps_calculation' => $inpsCalculation ? 1 : 0,
                'inail_calculation' => $inailCalculation ? 1 : 0,
                'fattura_tra_privati_calculation' => $fatturaCalculation ? 1 : 0,
                'diritto_annuale_payment' => $dirittoAnnuale ? 1 : 0,
                'payments' => $payments->count(),
            ],
            'blockers' => $blockers,
        ];
    }

    /**
     * @throws RuntimeException if any payment reachable from this year (this
     *                          year's own, or a next-year one scheduled from it) has been touched.
     */
    public function delete(Customer $customer, int $year): void
    {
        $preview = $this->preview($customer, $year);

        if (! $preview['has_data']) {
            return;
        }

        if (! $preview['deletable']) {
            $lines = collect($preview['blockers'])
                ->map(fn ($b) => "{$b['type']} #{$b['id']} ({$b['year']}, €".number_format($b['amount'], 2).', '.$b['status'].($b['belongs_to_next_year'] ? ', belongs to '.($year + 1) : '').')')
                ->implode('; ');

            throw new RuntimeException(
                "Cannot delete {$year}: it has paid, cancelled, receipted or self-reported payments attached, some of which belong to ".($year + 1).
                " because they were scheduled from this year's calculation ({$lines}). Unmark them as paid first if this year genuinely needs to be removed."
            );
        }

        DB::transaction(function () use ($customer, $year) {
            // Each cascadeOnDelete FK removes that calculation's payments —
            // including the next-year ones described in the class docblock —
            // automatically. Safe here because preview() already confirmed
            // none of them were touched.
            TaxCalculation::where('customer_id', $customer->id)->where('tax_year', $year)->delete();
            InpsCalculation::where('customer_id', $customer->id)->where('tax_year', $year)->delete();
            InailCalculation::where('customer_id', $customer->id)->where('tax_year', $year)->delete();
            FatturaTraPrivatiCalculation::where('customer_id', $customer->id)->where('tax_year', $year)->delete();
            // Not owned by any calculation row — delete it directly.
            DirittoAnnualePayment::where('customer_id', $customer->id)->where('payment_year', $year)->delete();
        });
    }

    /** Whether year + 1 has stored figures that were computed using data this deletion just removed. */
    public function staleFollowingYear(Customer $customer, int $year): bool
    {
        return TaxCalculation::where('customer_id', $customer->id)->where('tax_year', $year + 1)->exists()
            || InpsCalculation::where('customer_id', $customer->id)->where('tax_year', $year + 1)->exists();
    }

    private function isTouched(TaxPayment|InpsPayment|InailPayment|FatturaTraPrivatiPayment|DirittoAnnualePayment $payment): bool
    {
        return in_array($payment->status, [PaymentStatus::Paid, PaymentStatus::Cancelled], true)
            || (float) $payment->paid_amount > 0
            || $payment->uploaded_pdf !== null
            || $payment->reported_paid_at !== null;
    }
}
