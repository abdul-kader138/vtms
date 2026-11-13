<?php

namespace App\Services\Tax;

use App\Enums\CustomerType;
use App\Models\Customer;
use App\Models\InailCalculation;
use App\Models\TaxCalculation;

class InailCalculationService
{
    /**
     * Base = taxable_income directly, no coefficient multiplier. This is the
     * confirmed decision: use the "automatic calculator" formula from VTMS,
     * not the alternate 65%-coefficient formula that only ever ran in that
     * app's manual historical-backfill path.
     */
    public function calculateForYear(Customer $customer, int $year, TaxCalculation $taxCalculation): ?InailCalculation
    {
        if ($customer->customer_type !== CustomerType::Artigiani) {
            return null;
        }

        if ($customer->inail_rate === null || $customer->inail_minimum_payment === null) {
            return null;
        }

        $taxableIncome = (float) $taxCalculation->taxable_income;
        $rate = (float) $customer->inail_rate;
        $minimum = (float) $customer->inail_minimum_payment;

        $calculated = round($taxableIncome * $rate / 100, 2);
        $final = max($calculated, $minimum);

        return InailCalculation::updateOrCreate(
            ['customer_id' => $customer->id, 'tax_year' => $year],
            [
                'taxable_income' => $taxableIncome,
                'inail_rate' => $rate,
                'inail_calculated_amount' => $calculated,
                'inail_minimum_payment' => $minimum,
                'inail_final_amount' => $final,
                'ateco_code' => $customer->inail_ateco_code,
            ]
        );
    }
}
