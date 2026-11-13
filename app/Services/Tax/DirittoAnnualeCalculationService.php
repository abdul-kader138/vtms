<?php

namespace App\Services\Tax;

use App\Enums\CustomerType;
use App\Models\Customer;
use App\Models\DirittoAnnualeBracket;
use App\Models\TaxCalculation;

class DirittoAnnualeCalculationService
{
    /**
     * No separate "calculation" table for Diritto Annuale — it's a direct
     * bracket lookup. This service only matches a bracket and returns it
     * (or null); PaymentScheduleService::scheduleDirittoAnnualePayment()
     * does the actual persistence, since that's where the due-date lives.
     */
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
