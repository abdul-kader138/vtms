<?php

namespace App\Services\Tax;

use App\Enums\CustomerType;
use App\Models\Customer;
use App\Models\DirittoAnnualeBracket;
use App\Models\Setting;
use App\Models\TaxCalculation;

class DirittoAnnualeCalculationService
{
    /**
     * No separate "calculation" table for Diritto Annuale — it's a direct
     * bracket lookup. This service only matches a bracket and returns it
     * (or null); PaymentScheduleService::scheduleDirittoAnnualePayment()
     * does the actual persistence, since that's where the due-date lives.
     */
    /**
     * Law rules: a sole trader pays a fixed amount by Registro Imprese
     * section, plus the chamber surcharge when it applies, rounded to the
     * euro (€44 × 1.2 = €52.80 → €53). Gestione Separata professionals are
     * not in the Registro Imprese and pay nothing.
     */
    public function amountFor(Customer $customer): ?float
    {
        if (! in_array($customer->customer_type, [CustomerType::Commercianti, CustomerType::Artigiani], true)) {
            return null;
        }

        $base = (float) ($customer->chamber_section === 'ordinaria'
            ? Setting::get('diritto_annuale_base_ordinaria', 100)
            : Setting::get('diritto_annuale_base_speciale', 44));

        // Null on a model created in this request: the DB default (true) isn't read back.
        $surcharge = ($customer->chamber_surcharge ?? true) ? (float) Setting::get('diritto_annuale_surcharge_percent', 20) : 0.0;

        return round($base * (1 + $surcharge / 100));
    }

    public function matchBracket(Customer $customer, int $year, TaxCalculation $taxCalculation): ?DirittoAnnualeBracket
    {
        if (! in_array($customer->customer_type, [CustomerType::Commercianti, CustomerType::Artigiani], true)) {
            return null;
        }

        $totalSales = (float) $taxCalculation->total_sales;

        $bracket = DirittoAnnualeBracket::where('bracket_year', $year)
            ->where('is_active', true)
            ->where('revenue_from', '<=', $totalSales)
            ->where(function ($q) use ($totalSales) {
                $q->whereNull('revenue_to')->orWhere('revenue_to', '>=', $totalSales);
            })
            ->first();

        if (! $bracket || (float) $bracket->amount <= 0) {
            return null;
        }

        return $bracket;
    }
}
