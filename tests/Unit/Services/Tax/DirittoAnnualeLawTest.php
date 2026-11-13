<?php

namespace Tests\Unit\Services\Tax;

use App\Models\Customer;
use App\Models\DirittoAnnualePayment;
use App\Models\IncomeData;
use App\Models\Setting;
use App\Services\Tax\TaxCalculationOrchestrator;
use App\Support\Tax\TaxRules;
use Database\Seeders\InpsContributionRuleSeeder;
use Database\Seeders\InpsRateSlabSeeder;
use Database\Seeders\TaxSettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DirittoAnnualeLawTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(TaxSettingsSeeder::class);
        $this->seed(InpsRateSlabSeeder::class);
        $this->seed(InpsContributionRuleSeeder::class);
        Setting::set(TaxRules::SETTING, TaxRules::LAW, 'tax');
    }

    private function payment(array $attrs): ?DirittoAnnualePayment
    {
        $c = Customer::create($attrs + ['name' => 'DA', 'customer_type' => 'artigiani', 'coefficient_of_profitability' => 67, 'tax_rate' => 15, 'business_start_date' => '2020-01-01']);
        IncomeData::create(['customer_id' => $c->id, 'source' => 'sdi_fattura', 'transaction_date' => '2025-04-01', 'sales_amount' => 20000, 'taxable_sales' => 20000, 'sale_taxes' => 0, 'tax_year' => 2025]);
        app(TaxCalculationOrchestrator::class)->runForCustomerAndYear($c->fresh(), 2025);

        return DirittoAnnualePayment::where('customer_id', $c->id)->first();
    }

    public function test_sezione_speciale_with_surcharge_is_53_due_30_june(): void
    {
        $p = $this->payment([]);

        $this->assertEquals(53.00, (float) $p->amount); // 44 × 1.2 = 52.80 → 53
        $this->assertSame('2025-06-30', $p->due_date->toDateString());
        $this->assertNull($p->diritto_annuale_bracket_id);
    }

    public function test_ordinaria_without_surcharge_and_start_year_due_date(): void
    {
        $this->assertEquals(100.00, (float) $this->payment(['chamber_section' => 'ordinaria', 'chamber_surcharge' => false])->amount);

        // Registered 10 Mar 2025: due within 30 days (9 Apr 2025, a Wednesday).
        $this->assertSame('2025-04-09', $this->payment(['business_start_date' => '2025-03-10'])->due_date->toDateString());
    }

    public function test_gestione_separata_pays_nothing(): void
    {
        $this->assertNull($this->payment(['customer_type' => 'gestione_separata', 'coefficient_of_profitability' => 78]));
    }
}
