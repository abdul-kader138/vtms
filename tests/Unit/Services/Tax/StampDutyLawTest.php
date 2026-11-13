<?php

namespace Tests\Unit\Services\Tax;

use App\Models\Customer;
use App\Models\IncomeData;
use App\Models\Setting;
use App\Services\Tax\FatturaTraPrivatiCalculationService;
use App\Services\Tax\TaxOverviewService;
use App\Support\Tax\TaxRules;
use Database\Seeders\TaxSettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StampDutyLawTest extends TestCase
{
    use RefreshDatabase;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(TaxSettingsSeeder::class);
        $this->customer = Customer::create(['name' => 'Stamp', 'customer_type' => 'commercianti', 'coefficient_of_profitability' => 40, 'tax_rate' => 15]);

        $rows = [
            ['sdi_fattura', 200.00, 'Si'],    // counts
            ['sdi_fattura', 77.47, ''],       // exactly the threshold: law no, legacy yes
            ['sdi_fattura', 50.00, 'SI'],     // flagged by the issuer: law counts it
            ['sdi_fattura', -90.00, 'Si'],    // credit note over €77.47: counts
            ['sdi_fattura', 120.00, ''],      // over, but sent without the stamp: counts + warning
            ['privati_device', 450.00, null], // a day's cash-register total: never
        ];
        foreach ($rows as $i => [$source, $amount, $bollo]) {
            IncomeData::create([
                'customer_id' => $this->customer->id, 'source' => $source, 'transaction_date' => '2025-0'.($i + 1).'-10',
                'sales_amount' => $amount, 'taxable_sales' => $amount, 'sale_taxes' => 0, 'tax_year' => 2025,
                'meta' => $bollo === null ? [] : ['bollo_virtuale' => $bollo],
            ]);
        }
    }

    public function test_law_counts_only_e_invoices_over_the_threshold_or_flagged(): void
    {
        Setting::set(TaxRules::SETTING, TaxRules::LAW, 'tax');

        $calc = app(FatturaTraPrivatiCalculationService::class)->calculateForYear($this->customer, 2025);

        $this->assertSame(4, $calc->total_invoices);
        $this->assertEquals(8.00, (float) $calc->total_payment_amount);
        $this->assertEquals(460.00, (float) $calc->total_sales_amount);

        $warnings = collect(app(TaxOverviewService::class)->stampDutyWarnings($this->customer, 2025))->pluck('message')->implode(' ');
        $this->assertStringContainsString('1 invoices of 2025', $warnings);
    }

    public function test_legacy_keeps_the_vtms_count(): void
    {
        $calc = app(FatturaTraPrivatiCalculationService::class)->calculateForYear($this->customer, 2025);

        // ≥ 77.47 on any source: 200, 77.47, 120, 450.
        $this->assertSame(4, $calc->total_invoices);
        $this->assertSame([], app(TaxOverviewService::class)->stampDutyWarnings($this->customer, 2025));
    }
}
