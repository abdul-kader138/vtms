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
        Setting::set('forfettario_immediate_exit_threshold', 100000.00, 'tax');
        Setting::set('payment_reminder_days', 7, 'tax');
        // Diritto Annuale, sole traders (D.M. 21/04/2011 halved by D.L. 90/2014):
        // base by Registro Imprese section + the chamber's usual surcharge.
        Setting::set('diritto_annuale_base_speciale', 44.00, 'tax');
        Setting::set('diritto_annuale_base_ordinaria', 100.00, 'tax');
        Setting::set('diritto_annuale_surcharge_percent', 20.00, 'tax');
    }
}
