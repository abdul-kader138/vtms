<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            ShieldSeeder::class,
            TaxSettingsSeeder::class,
            InpsRateSlabSeeder::class,
            InpsContributionRuleSeeder::class,
            DirittoAnnualeBracketSeeder::class,
        ]);

        // Fictional customers, income and portal logins — never in production.
        if (app()->environment(['local', 'testing'])) {
            $this->call(DemoTaxDataSeeder::class);
            // Real client, built from docs/ (gitignored source documents) —
            // skips itself automatically when docs/ isn't present.
            $this->call(ClientSolimanCommerciantiSeeder::class);
        }
    }
}
