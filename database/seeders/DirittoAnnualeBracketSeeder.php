<?php

namespace Database\Seeders;

use App\Models\DirittoAnnualeBracket;
use Illuminate\Database\Seeder;

class DirittoAnnualeBracketSeeder extends Seeder
{
    /**
     * VTMS never had a real bracket table for Diritto Annuale (it stored a
     * flat manual amount per customer). These rows are PLACEHOLDERS only —
     * an admin must replace them with real Camera di Commercio figures.
     */
    public function run(): void
    {
        $year = (int) date('Y');

        $rows = [
            ['bracket_year' => $year, 'revenue_from' => 0, 'revenue_to' => 100000, 'amount' => 53.00, 'description' => 'PLACEHOLDER — replace with real Camera di Commercio figures'],
            ['bracket_year' => $year, 'revenue_from' => 100000.01, 'revenue_to' => null, 'amount' => 90.00, 'description' => 'PLACEHOLDER — replace with real Camera di Commercio figures'],
        ];

        foreach ($rows as $row) {
            DirittoAnnualeBracket::updateOrCreate(
                ['bracket_year' => $row['bracket_year'], 'revenue_from' => $row['revenue_from']],
                $row + ['is_active' => true]
            );
        }
    }
}
