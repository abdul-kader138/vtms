<?php

namespace Database\Seeders;

use App\Enums\CustomerType;
use App\Models\InpsRateSlab;
use Illuminate\Database\Seeder;

class InpsRateSlabSeeder extends Seeder
{
    /**
     * Real current rates, confirmed against VTMS's own db.sql seed data
     * (sma_inps_rate_slabs) — not placeholders.
     */
    public function run(): void
    {
        $rows = [
            // 2025 — Commercianti & Artigiani share the same progressive structure
            ['slab_year' => 2025, 'customer_type' => CustomerType::Commercianti, 'income_from' => 1, 'income_to' => 10000, 'inps_rate' => 20.00, 'fixed_amount' => 0, 'description' => 'Commercianti 2025 — first bracket'],
            ['slab_year' => 2025, 'customer_type' => CustomerType::Commercianti, 'income_from' => 10001, 'income_to' => 12000, 'inps_rate' => 22.25, 'fixed_amount' => null, 'description' => 'Commercianti 2025 — second bracket'],
            ['slab_year' => 2025, 'customer_type' => CustomerType::Commercianti, 'income_from' => 12001, 'income_to' => 100000, 'inps_rate' => 24.75, 'fixed_amount' => null, 'description' => 'Commercianti 2025 — third bracket'],
            ['slab_year' => 2025, 'customer_type' => CustomerType::Commercianti, 'income_from' => 100001, 'income_to' => null, 'inps_rate' => 25.25, 'fixed_amount' => null, 'description' => 'Commercianti 2025 — top bracket'],
            ['slab_year' => 2024, 'customer_type' => CustomerType::Commercianti, 'income_from' => 1, 'income_to' => 18555, 'inps_rate' => 21.00, 'fixed_amount' => null, 'description' => 'Commercianti 2024 — flat rate'],

            ['slab_year' => 2025, 'customer_type' => CustomerType::Artigiani, 'income_from' => 1, 'income_to' => 10000, 'inps_rate' => 20.00, 'fixed_amount' => 0, 'description' => 'Artigiani 2025 — first bracket'],
            ['slab_year' => 2025, 'customer_type' => CustomerType::Artigiani, 'income_from' => 10001, 'income_to' => 12000, 'inps_rate' => 22.25, 'fixed_amount' => null, 'description' => 'Artigiani 2025 — second bracket'],
            ['slab_year' => 2025, 'customer_type' => CustomerType::Artigiani, 'income_from' => 12001, 'income_to' => 100000, 'inps_rate' => 24.75, 'fixed_amount' => null, 'description' => 'Artigiani 2025 — third bracket'],
            ['slab_year' => 2025, 'customer_type' => CustomerType::Artigiani, 'income_from' => 100001, 'income_to' => null, 'inps_rate' => 25.25, 'fixed_amount' => null, 'description' => 'Artigiani 2025 — top bracket'],
            ['slab_year' => 2024, 'customer_type' => CustomerType::Artigiani, 'income_from' => 1, 'income_to' => 18555, 'inps_rate' => 21.00, 'fixed_amount' => null, 'description' => 'Artigiani 2024 — flat rate'],

            ['slab_year' => 2025, 'customer_type' => CustomerType::GestioneSeparata, 'income_from' => 0, 'income_to' => 200000, 'inps_rate' => 21.50, 'fixed_amount' => null, 'description' => 'Gestione Separata 2025 — flat rate'],
        ];

        foreach ($rows as $row) {
            InpsRateSlab::updateOrCreate(
                ['slab_year' => $row['slab_year'], 'customer_type' => $row['customer_type'], 'income_from' => $row['income_from']],
                $row + ['is_active' => true]
            );
        }

        $this->carryForwardToCurrentYear();
    }

    /**
     * INPS publishes each year's rates in a circular early in the year, so
     * the current year often has no slabs yet — and the calculator then
     * silently computes €0 INPS. Copy the latest known year forward for any
     * customer type that has nothing for the current year, clearly labelled
     * so an admin replaces it. firstOrCreate: never overwrites real rows an
     * admin has already entered.
     */
    private function carryForwardToCurrentYear(): void
    {
        $year = (int) now()->year;

        foreach (CustomerType::cases() as $type) {
            if (InpsRateSlab::where('slab_year', $year)->where('customer_type', $type)->exists()) {
                continue;
            }

            $latest = InpsRateSlab::where('customer_type', $type)->where('slab_year', '<', $year)->max('slab_year');

            if ($latest === null) {
                continue;
            }

            InpsRateSlab::where('customer_type', $type)->where('slab_year', $latest)->get()
                ->each(fn (InpsRateSlab $slab) => InpsRateSlab::firstOrCreate(
                    ['slab_year' => $year, 'customer_type' => $type, 'income_from' => $slab->income_from],
                    [
                        'income_to' => $slab->income_to,
                        'inps_rate' => $slab->inps_rate,
                        'fixed_amount' => $slab->fixed_amount,
                        'description' => "CARRIED FORWARD from {$latest} — verify against the INPS circular for {$year}",
                        'is_active' => true,
                    ]
                ));
        }
    }
}
