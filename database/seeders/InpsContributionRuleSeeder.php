<?php

namespace Database\Seeders;

use App\Enums\CustomerType;
use App\Models\InpsContributionRule;
use Illuminate\Database\Seeder;

/**
 * INPS Gestione IVS parameters for Artigiani / Commercianti.
 *
 * 2024 (INPS circ. 33/2024), 2025 (circ. 38/2025), 2026 (circ. 14/2026,
 * 9 Feb 2026: fixed €4,521.36 artigiani / €4,611.64 commercianti): minimale, threshold
 * for the extra 1%, and the massimale for those insured from 1996 on
 * (the usual case for forfettari). Commercianti pay 0.48 points more than
 * Artigiani. Check each new year's circular and edit under
 * Administration → INPS contribution rules.
 */
class InpsContributionRuleSeeder extends Seeder
{
    private const YEARS = [
        2024 => ['minimum_income' => 18415, 'threshold' => 55008, 'maximum_income' => 119650, 'source' => 'INPS circ. 33/2024'],
        2025 => ['minimum_income' => 18555, 'threshold' => 55448, 'maximum_income' => 120607, 'source' => 'INPS circ. 38/2025'],
        2026 => ['minimum_income' => 18808, 'threshold' => 56224, 'maximum_income' => 122295, 'source' => 'INPS circ. 14/2026'],
    ];

    private const RATES = [
        'artigiani' => [24.00, 25.00],
        'commercianti' => [24.48, 25.48],
    ];

    public function run(): void
    {
        foreach (self::YEARS as $year => $values) {
            foreach (self::RATES as $type => [$rate, $above]) {
                $existing = InpsContributionRule::where(['year' => $year, 'customer_type' => $type])->first();

                // Never overwrite figures an admin entered; do replace a
                // placeholder copied forward before the circular was known.
                if ($existing && $existing->isVerified()) {
                    continue;
                }

                InpsContributionRule::updateOrCreate(
                    ['year' => $year, 'customer_type' => $type],
                    $values + ['rate' => $rate, 'rate_above_threshold' => $above, 'maternity_fee' => 7.44]
                );
            }
        }

        $this->carryForwardToCurrentYear();
    }

    /** Same idea as InpsRateSlabSeeder: never leave the current year empty, but label it. */
    private function carryForwardToCurrentYear(): void
    {
        $year = (int) now()->year;

        foreach ([CustomerType::Artigiani, CustomerType::Commercianti] as $type) {
            $latest = InpsContributionRule::where('customer_type', $type)->where('year', '<=', $year)->orderByDesc('year')->first();

            if ($latest === null || $latest->year === $year) {
                continue;
            }

            InpsContributionRule::firstOrCreate(
                ['year' => $year, 'customer_type' => $type],
                $latest->only(['minimum_income', 'rate', 'maternity_fee', 'threshold', 'rate_above_threshold', 'maximum_income'])
                    + ['source' => "CARRIED FORWARD from {$latest->year} — verify against the INPS circular for {$year}"]
            );
        }
    }
}
