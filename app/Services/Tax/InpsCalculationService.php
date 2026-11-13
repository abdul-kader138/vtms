<?php

namespace App\Services\Tax;

use App\Enums\InpsCalculationMethod;
use App\Models\Customer;
use App\Models\InpsCalculation;
use App\Models\InpsRateSlab;
use App\Models\Setting;
use App\Models\TaxCalculation;

class InpsCalculationService
{
    public function calculateForYear(Customer $customer, int $year, TaxCalculation $taxCalculation): InpsCalculation
    {
        $taxableIncome = (float) $taxCalculation->taxable_income;

        $slabs = InpsRateSlab::where('slab_year', $year)
            ->where('is_active', true)
            ->where(function ($q) use ($customer) {
                $q->where('customer_type', $customer->customer_type)
                    ->orWhereNull('customer_type');
            })
            ->orderBy('income_from')
            ->get()
            // A type-specific slab takes precedence over a generic (null-type)
            // slab starting at the same income_from.
            ->groupBy('income_from')
            ->map(fn ($group) => $group->sortBy(fn ($s) => $s->customer_type === null ? 1 : 0)->first())
            ->sortBy('income_from')
            ->values();

        $slabDetails = [];
        $inpsAmount = 0.0;
        $method = InpsCalculationMethod::Percentage;

        if ($slabs->isNotEmpty()) {
            $first = $slabs->first();
            $firstUpperBound = $first->income_to === null ? INF : (float) $first->income_to;

            if ((float) $first->fixed_amount > 0 && $taxableIncome <= $firstUpperBound) {
                $inpsAmount = (float) $first->fixed_amount;
                $method = InpsCalculationMethod::Minimum;
                $slabDetails[] = [
                    'income_from' => (float) $first->income_from,
                    'income_to' => $first->income_to === null ? null : (float) $first->income_to,
                    'rate' => (float) $first->inps_rate,
                    'portion' => 0.0,
                    'tax' => $inpsAmount,
                    'method' => 'minimum',
                ];
            } else {
                foreach ($slabs as $slab) {
                    $incomeFrom = (float) $slab->income_from;
                    $incomeTo = $slab->income_to === null ? null : (float) $slab->income_to;
                    $rangeEnd = min($taxableIncome, $incomeTo ?? $taxableIncome);
                    $portion = max(0, $rangeEnd - $incomeFrom);

                    if ($portion > 0) {
                        $tax = $portion * ((float) $slab->inps_rate / 100);
                        $inpsAmount += $tax;
                        $slabDetails[] = [
                            'income_from' => $incomeFrom,
                            'income_to' => $incomeTo,
                            'rate' => (float) $slab->inps_rate,
                            'portion' => $portion,
                            'tax' => round($tax, 2),
                        ];
                    }

                    if ($rangeEnd >= $taxableIncome) {
                        break;
                    }
                }
            }
        }

        $inpsAmount = round($inpsAmount, 2);
        $averageRate = $taxableIncome > 0 ? round($inpsAmount / $taxableIncome * 100, 2) : 0;

        $discountPercentage = $customer->inps_discount_eligible
            ? (float) Setting::get('inps_discount_percent', 35)
            : 0.0;
        $discountAmount = $discountPercentage > 0 ? round($inpsAmount * $discountPercentage / 100, 2) : 0.0;
        $afterDiscount = round($inpsAmount - $discountAmount, 2);

        return InpsCalculation::updateOrCreate(
            ['customer_id' => $customer->id, 'tax_year' => $year],
            [
                'taxable_income' => $taxableIncome,
                'inps_rate' => $averageRate,
                'inps_amount' => $inpsAmount,
                'discount_percentage' => $discountPercentage,
                'discount_amount' => $discountAmount,
                'inps_amount_after_discount' => $afterDiscount,
                'slab_details' => $slabDetails,
                'calculation_method' => $method,
            ]
        );
    }
}
