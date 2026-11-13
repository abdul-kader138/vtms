<?php

namespace App\Services\Tax;

use App\Enums\CustomerType;
use App\Enums\InpsCalculationMethod;
use App\Models\Customer;
use App\Models\InpsCalculation;
use App\Models\InpsContributionRule;
use App\Models\InpsRateSlab;
use App\Models\Setting;
use App\Models\TaxCalculation;
use App\Support\Tax\TaxRules;

class InpsCalculationService
{
    public function calculateForYear(Customer $customer, int $year, TaxCalculation $taxCalculation): InpsCalculation
    {
        $taxableIncome = $taxCalculation->grossIncome();

        if (TaxRules::isLaw() && in_array($customer->customer_type, [CustomerType::Artigiani, CustomerType::Commercianti], true)
            && ($rule = InpsContributionRule::for($customer->customer_type, $year))) {
            return $this->fixedPlusPercentage($customer, $year, $taxableIncome, $rule);
        }

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
                'fixed_amount' => null,
                'variable_amount' => null,
                'discount_percentage' => $discountPercentage,
                'discount_amount' => $discountAmount,
                'inps_amount_after_discount' => $afterDiscount,
                'slab_details' => $slabDetails,
                'calculation_method' => $method,
            ]
        );
    }

    /**
     * Artigiani / Commercianti (Gestione IVS):
     *  - fixed contributions on the minimale, due whatever the income,
     *    pro-rated by months of activity in the start year;
     *  - on income above the minimale: `rate` up to the threshold, one point
     *    more above it, nothing above the massimale.
     * The income base is the forfettario income before deducting INPS.
     * The forfettario reduction (35%) applies to both parts.
     */
    private function fixedPlusPercentage(Customer $customer, int $year, float $income, InpsContributionRule $rule): InpsCalculation
    {
        $start = $customer->business_start_date;
        $months = match (true) {
            $start === null || $start->year < $year => 12,
            $start->year > $year => 0,
            default => 13 - $start->month,
        };
        $share = $months / 12;

        $minimum = round((float) $rule->minimum_income * $share, 2);
        $threshold = (float) $rule->threshold;
        $maximum = (float) $rule->maximum_income;
        $rate = (float) $rule->rate;
        $rateAbove = (float) $rule->rate_above_threshold;

        $fixed = round($minimum * $rate / 100 + (float) $rule->maternity_fee * $share, 2);

        $lower = max(0, min($income, $threshold) - $minimum);
        $upper = max(0, min($income, $maximum) - max($threshold, $minimum));
        $variable = round($lower * $rate / 100 + $upper * $rateAbove / 100, 2);

        $details = [[
            'income_from' => 0.0, 'income_to' => $minimum, 'rate' => $rate, 'portion' => $minimum,
            'tax' => $fixed, 'method' => 'minimum', 'months' => $months,
        ]];
        if ($lower > 0) {
            $details[] = ['income_from' => $minimum, 'income_to' => $threshold, 'rate' => $rate, 'portion' => round($lower, 2), 'tax' => round($lower * $rate / 100, 2)];
        }
        if ($upper > 0) {
            $details[] = ['income_from' => $threshold, 'income_to' => $maximum, 'rate' => $rateAbove, 'portion' => round($upper, 2), 'tax' => round($upper * $rateAbove / 100, 2)];
        }

        $total = round($fixed + $variable, 2);
        $discountPercentage = $customer->inps_discount_eligible ? (float) Setting::get('inps_discount_percent', 35) : 0.0;
        $discountAmount = round($total * $discountPercentage / 100, 2);

        return InpsCalculation::updateOrCreate(
            ['customer_id' => $customer->id, 'tax_year' => $year],
            [
                'taxable_income' => $income,
                'inps_rate' => $income > 0 ? round($total / $income * 100, 2) : 0,
                'inps_amount' => $total,
                'fixed_amount' => $fixed,
                'variable_amount' => $variable,
                'discount_percentage' => $discountPercentage,
                'discount_amount' => $discountAmount,
                'inps_amount_after_discount' => round($total - $discountAmount, 2),
                'slab_details' => $details,
                'calculation_method' => InpsCalculationMethod::FixedPlusPercentage,
                'notes' => $rule->isVerified() ? null : $rule->source,
            ]
        );
    }

    /** The fixed / above-minimum split of the amount actually due (after the reduction). */
    public static function splitAfterDiscount(InpsCalculation $ic): array
    {
        $factor = 1 - (float) $ic->discount_percentage / 100;
        $fixed = round((float) $ic->fixed_amount * $factor, 2);

        return ['fixed' => $fixed, 'variable' => round((float) $ic->inps_amount_after_discount - $fixed, 2)];
    }
}
