<?php

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

class TaxSettingsSeeder extends Seeder
{
    public function run(): void
    {
        Setting::set('inps_discount_percent', 35.00, 'tax');
        Setting::set('stamp_duty_threshold', 77.47, 'tax');
        Setting::set('stamp_duty_fee', 2.00, 'tax');
    }
}
