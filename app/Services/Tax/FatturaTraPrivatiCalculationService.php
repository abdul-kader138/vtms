<?php

namespace App\Services\Tax;

use App\Enums\IncomeSource;
use App\Models\Customer;
use App\Models\FatturaTraPrivatiCalculation;
use App\Models\IncomeData;
use App\Models\Setting;
use App\Support\Tax\TaxRules;
use Illuminate\Database\Eloquent\Builder;

class FatturaTraPrivatiCalculationService
{
    /**
     * Virtual stamp duty. Confirmed bug-fix from the original VTMS app:
     * count qualifying invoices across BOTH income sources (sdi_fattura and
     * privati_device) — the legacy app only ever counted privati_device,
     * silently missing qualifying SDI-imported invoices.
     */
    /**
     * Documents that carry the €2 virtual stamp duty, by issue date.
     *
     * Law: e-invoices (incl. credit notes) for more than €77.47, or flagged
     * "Bollo virtuale" by the issuer. Cash-register rows are DAILY TOTALS,
     * not single receipts, and are not part of the quarterly F24 payment.
     * Legacy: every income row ≥ the threshold, both sources (VTMS fix).
     */
    public static function qualifying(int $customerId, int $year, float $threshold): Builder
    {
        $query = IncomeData::where('customer_id', $customerId)->where('tax_year', $year);

        if (! TaxRules::isLaw()) {
            return $query->where('sales_amount', '>=', $threshold);
        }

        return $query->where('source', IncomeSource::SdiFattura)
            ->where(fn ($q) => $q->where('sales_amount', '>', $threshold)
                ->orWhere('sales_amount', '<', -$threshold)
                ->orWhereIn('meta->bollo_virtuale', ['SI', 'Si', 'si']));
    }

    public function calculateForYear(Customer $customer, int $year): ?FatturaTraPrivatiCalculation
    {
        $threshold = (float) Setting::get('stamp_duty_threshold', 77.47);
        $fee = (float) Setting::get('stamp_duty_fee', 2.00);

        $qualifying = self::qualifying($customer->id, $year, $threshold);

        $totalInvoices = $qualifying->count();

        if ($totalInvoices === 0) {
            return null;
        }

        $totalSalesAmount = (float) $qualifying->sum('sales_amount');
        $totalPaymentAmount = round($totalInvoices * $fee, 2);

        // Law: a credit note is stored negative but still carries the stamp.
        $totalSalesAmount = TaxRules::isLaw()
            ? (float) self::qualifying($customer->id, $year, $threshold)->get(['sales_amount'])->sum(fn ($r) => abs((float) $r->sales_amount))
            : $totalSalesAmount;

        return FatturaTraPrivatiCalculation::updateOrCreate(
            ['customer_id' => $customer->id, 'tax_year' => $year],
            [
                'total_invoices' => $totalInvoices,
                'total_sales_amount' => $totalSalesAmount,
                'payment_per_invoice' => $fee,
                'total_payment_amount' => $totalPaymentAmount,
                'minimum_invoice_amount' => $threshold,
            ]
        );
    }
}
