<?php

use App\Support\Tax\TaxRules;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Without this, TaxRules::mode() falls back to "legacy" whenever the
     * settings row is missing (Setting::get() default) — which is exactly
     * what happened in production: deploy.sh only runs `migrate`, never
     * `db:seed`, so TaxSettingsSeeder (which only runs locally/on demand)
     * never created this row there. insertOrIgnore makes this safe to ship
     * even where an admin already deliberately chose "legacy" — it never
     * overwrites an existing row.
     *
     * Skipped under `testing`: dozens of existing tests deliberately rely
     * on the implicit "no row = legacy" default to exercise legacy-mode
     * behavior without setting it explicitly every time (e.g. CashBasisTest,
     * PaymentScheduleServiceTest, StampDutyLawTest). RefreshDatabase re-runs
     * every migration per test, so without this guard every test would
     * start in "law" mode regardless of what it's actually testing.
     */
    public function up(): void
    {
        if (app()->environment('testing')) {
            return;
        }

        DB::table('settings')->insertOrIgnore([
            'group' => 'tax',
            'key' => TaxRules::SETTING,
            'value' => TaxRules::LAW,
            'type' => 'string',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        // Intentionally no-op: removing the row would put the app back
        // into the silent "legacy" fallback this migration exists to fix.
    }
};
