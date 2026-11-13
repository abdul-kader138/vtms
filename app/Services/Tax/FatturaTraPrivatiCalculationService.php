<?php

namespace App\Services\Tax;

use App\Models\Customer;
use App\Models\FatturaTraPrivatiCalculation;
use App\Models\IncomeData;
use App\Models\Setting;

class FatturaTraPrivatiCalculationService
{
    /**
     * Virtual stamp duty. Confirmed bug-fix from the original VTMS app:
     * count qualifying invoices across BOTH income sources (sdi_fattura and
     * privati_device) — the legacy app only ever counted privati_device,
     * silently missing qualifying SDI-imported invoices.
     */
    public function calculateForYear(Customer $customer, int $year): ?FatturaTraPrivatiCalculation
    {
        $threshold = (float) Setting::get('stamp_duty_threshold', 77.47);
        $fee = (float) Setting::get('stamp_duty_fee', 2.00);

        $qualifying = IncomeData::where('customer_id', $customer->id)
            ->where('tax_year', $year)
            ->where('sales_amount', '>=', $threshold);

        $totalInvoices = $qualifying->count();

        if ($totalInvoices === 0) {
            return null;
        }

        $totalSalesAmount = (float) $qualifying->sum('sales_amount');
        $totalPaymentAmount = round($totalInvoices * $fee, 2);

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
