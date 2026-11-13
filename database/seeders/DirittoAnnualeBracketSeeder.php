<?php

namespace Database\Seeders;

use App\Models\DirittoAnnualeBracket;
use Illuminate\Database\Seeder;

class DirittoAnnualeBracketSeeder extends Seeder
{
    public const FIRST_YEAR = 2024;

    /**
     * VTMS never had a real bracket table for Diritto Annuale (it stored a
     * flat manual amount per customer — €53 for almost everyone in its
     * data). These rows are PLACEHOLDERS built from that figure: an admin
     * must replace them with the real Camera di Commercio amounts.
     *
     * Seeded for every year from FIRST_YEAR to the current one, so past
     * years calculated or backfilled today still get a payment. Uses
     * firstOrCreate so re-running the seeder (e.g. on deploy) never
     * overwrites real figures an admin has already entered.
     */
    public function run(): void
    {
        for ($year = self::FIRST_YEAR; $year <= (int) now()->year; $year++) {
            $rows = [
                ['revenue_from' => 0, 'revenue_to' => 100000, 'amount' => 53.00],
                ['revenue_from' => 100000.01, 'revenue_to' => null, 'amount' => 90.00],
            ];

            foreach ($rows as $row) {
                DirittoAnnualeBracket::firstOrCreate(
                    ['bracket_year' => $year, 'revenue_from' => $row['revenue_from']],
                    [
                        'revenue_to' => $row['revenue_to'],
                        'amount' => $row['amount'],
                        'description' => 'PLACEHOLDER — replace with real Camera di Commercio figures',
                        'is_active' => true,
                    ]
                );
            }
        }
    }
}
