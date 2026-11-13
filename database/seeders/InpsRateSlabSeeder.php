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
    }
}
