<?php

namespace Tests\Unit\Services\Tax;

use App\Enums\InpsCalculationMethod;
use App\Models\Customer;
use App\Models\IncomeData;
use App\Models\InpsCalculation;
use App\Models\InpsContributionRule;
use App\Models\InpsPayment;
use App\Models\Setting;
use App\Services\Tax\PaymentRegistry;
use App\Services\Tax\TaxCalculationOrchestrator;
use App\Support\Tax\F24Code;
use App\Support\Tax\TaxRules;
use Database\Seeders\InpsContributionRuleSeeder;
use Database\Seeders\InpsRateSlabSeeder;
use Database\Seeders\TaxSettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class InpsFixedContributionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-13');
        $this->seed(TaxSettingsSeeder::class);
        $this->seed(InpsRateSlabSeeder::class);
        $this->seed(InpsContributionRuleSeeder::class);
        Setting::set(TaxRules::SETTING, TaxRules::LAW, 'tax');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Gross forfettario income = revenue × coefficient; coefficient 100 keeps the numbers readable. */
    private function customer(string $type, float $grossIncome, array $attrs = [], int $year = 2025): Customer
    {
        $c = Customer::create($attrs + ['name' => ucfirst($type), 'customer_type' => $type, 'coefficient_of_profitability' => 100, 'tax_rate' => 15, 'business_start_date' => '2020-01-01']);
        IncomeData::create(['customer_id' => $c->id, 'source' => 'sdi_fattura', 'transaction_date' => "{$year}-03-01", 'sales_amount' => $grossIncome, 'taxable_sales' => $grossIncome, 'sale_taxes' => 0, 'tax_year' => $year]);

        return $c;
    }

    private function calc(Customer $c, int $year = 2025): InpsCalculation
    {
        return app(TaxCalculationOrchestrator::class)->runForCustomerAndYear($c->fresh(), $year)['inps'];
    }

    public function test_artigiano_pays_fixed_contributions_plus_rate_above_the_minimale(): void
    {
        $inps = $this->calc($this->customer('artigiani', 30000));

        $this->assertSame(InpsCalculationMethod::FixedPlusPercentage, $inps->calculation_method);
        $this->assertEquals(4460.64, (float) $inps->fixed_amount);   // 18,555 × 24% + 7.44
        $this->assertEquals(2746.80, (float) $inps->variable_amount); // (30,000 − 18,555) × 24%
        $this->assertEquals(7207.44, (float) $inps->inps_amount_after_discount);
    }

    public function test_commerciante_above_the_threshold_pays_the_extra_point(): void
    {
        $inps = $this->calc($this->customer('commercianti', 60000));

        $this->assertEquals(4549.70, (float) $inps->fixed_amount);
        // (55,448 − 18,555) × 24.48% + (60,000 − 55,448) × 25.48%
        $this->assertEquals(10191.26, (float) $inps->variable_amount);
    }

    public function test_low_income_still_owes_the_fixed_part_and_start_year_is_pro_rated(): void
    {
        $this->assertEquals(4460.64, (float) $this->calc($this->customer('artigiani', 8000))->inps_amount);

        // Started in September: 4 months of the minimale and maternity fee.
        $inps = $this->calc($this->customer('artigiani', 3000, ['business_start_date' => '2025-09-10']));
        $this->assertEquals(1486.88, (float) $inps->fixed_amount); // 6,185 × 24% + 2.48
    }

    public function test_forfettario_reduction_applies_to_both_parts(): void
    {
        $inps = $this->calc($this->customer('artigiani', 30000, ['inps_discount_eligible' => true]));

        $this->assertEquals(2522.60, (float) $inps->discount_amount);
        $this->assertEquals(4684.84, (float) $inps->inps_amount_after_discount);
    }

    public function test_schedule_fixed_installments_balance_and_advances(): void
    {
        $c = $this->customer('artigiani', 30000);
        $this->calc($c);

        $rows = InpsPayment::with('customer')->where('customer_id', $c->id)->orderBy('payment_year')->orderBy('installment_number')->get();
        $this->assertSame(
            [[2025, 1, '1115.16', '2025-05-16'], [2025, 2, '1115.16', '2025-08-20'], [2025, 3, '1115.16', '2025-11-17'], [2025, 4, '1115.16', '2026-02-16'],
                [2025, 5, '2746.80', '2026-06-30'], [2026, 6, '1373.40', '2026-06-30'], [2026, 7, '1373.40', '2026-11-30']],
            $rows->map(fn ($p) => [$p->payment_year, $p->installment_number, $p->amount, $p->due_date->toDateString()])->all()
        );

        $this->assertSame('AF', F24Code::for('inps', $rows[0]));
        $this->assertSame('AP', F24Code::for('inps', $rows[4]));
        $this->assertStringContainsString('1st advance on income above the minimum', PaymentRegistry::describe('inps', $rows[5]));

        // Next year with the same income: the advances cover the balance.
        IncomeData::create(['customer_id' => $c->id, 'source' => 'sdi_fattura', 'transaction_date' => '2026-03-01', 'sales_amount' => 30000, 'taxable_sales' => 30000, 'sale_taxes' => 0, 'tax_year' => 2026]);
        $this->calc($c, 2026);
        $this->assertFalse(InpsPayment::where('customer_id', $c->id)->where('payment_year', 2026)->where('installment_number', 5)->exists());
    }

    public function test_legacy_rules_keep_the_vtms_slabs(): void
    {
        Setting::set(TaxRules::SETTING, TaxRules::LEGACY, 'tax');

        $inps = $this->calc($this->customer('artigiani', 30000));

        $this->assertNotSame(InpsCalculationMethod::FixedPlusPercentage, $inps->calculation_method);
        $this->assertNull($inps->fixed_amount);
    }

    public function test_2026_uses_the_inps_circular_values(): void
    {
        $rule = \App\Models\InpsContributionRule::where('year', 2026)->where('customer_type', 'artigiani')->first();

        $this->assertSame('INPS circ. 14/2026', $rule->source);
        $this->assertEquals(4521.36, $rule->fixedAmount());
        $this->assertEquals(4611.64, \App\Models\InpsContributionRule::where('year', 2026)->where('customer_type', 'commercianti')->first()->fixedAmount());
        $this->assertNull($this->calc($this->customer('artigiani', 30000, year: 2026), 2026)->notes);
    }

    public function test_seeder_replaces_a_carried_forward_row_but_keeps_admin_edits(): void
    {
        \App\Models\InpsContributionRule::where('year', 2026)->where('customer_type', 'artigiani')->update(['source' => 'CARRIED FORWARD from 2025 — verify', 'minimum_income' => 1]);
        \App\Models\InpsContributionRule::where('year', 2026)->where('customer_type', 'commercianti')->update(['source' => 'Edited by admin', 'minimum_income' => 2]);

        $this->seed(InpsContributionRuleSeeder::class);

        $this->assertEquals(18808, (float) \App\Models\InpsContributionRule::where('year', 2026)->where('customer_type', 'artigiani')->value('minimum_income'));
        $this->assertEquals(2, (float) \App\Models\InpsContributionRule::where('year', 2026)->where('customer_type', 'commercianti')->value('minimum_income'));
    }
}
