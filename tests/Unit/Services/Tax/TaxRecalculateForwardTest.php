<?php

namespace Tests\Unit\Services\Tax;

use App\Models\Customer;
use App\Models\IncomeData;
use App\Models\Setting;
use App\Models\TaxCalculation;
use App\Services\Tax\TaxCalculationOrchestrator;
use App\Services\Tax\TaxYearDeletionService;
use App\Support\Tax\TaxRules;
use Database\Seeders\InpsContributionRuleSeeder;
use Database\Seeders\TaxSettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TaxCalculationOrchestrator::runForwardFromYear() exists because a year's
 * tax advances / INPS excess-income installments are scheduled INTO the
 * next year (see PaymentScheduleService), so editing or deleting a year
 * can leave later years' stored figures stale — not just year + 1.
 */
class TaxRecalculateForwardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(TaxSettingsSeeder::class);
        $this->seed(InpsContributionRuleSeeder::class);
        Setting::set(TaxRules::SETTING, TaxRules::LAW, 'tax');
    }

    private function customer(): Customer
    {
        return Customer::create([
            'name' => 'Forward Recalc Test Commerciante',
            'customer_type' => 'commercianti',
            'coefficient_of_profitability' => 40.00,
            'tax_rate' => 15.00,
            'business_start_date' => '2020-01-01',
        ]);
    }

    public function test_it_walks_every_following_year_that_has_data_and_stops_at_the_first_gap(): void
    {
        $customer = $this->customer();

        IncomeData::create([
            'customer_id' => $customer->id, 'source' => 'sdi_fattura', 'transaction_date' => '2023-03-01',
            'sales_amount' => 50000.00, 'taxable_sales' => 50000.00, 'sale_taxes' => 0, 'tax_year' => 2023,
        ]);
        IncomeData::create([
            'customer_id' => $customer->id, 'source' => 'sdi_fattura', 'transaction_date' => '2024-03-01',
            'sales_amount' => 50000.00, 'taxable_sales' => 50000.00, 'sale_taxes' => 0, 'tax_year' => 2024,
        ]);
        // No income for 2025 — the chain should stop there.

        $orchestrator = app(TaxCalculationOrchestrator::class);
        $orchestrator->runForCustomerAndYear($customer, 2023);
        $orchestrator->runForCustomerAndYear($customer, 2024);

        $results = $orchestrator->runForwardFromYear($customer, 2023);

        $this->assertCount(2, $results);
        $this->assertSame([2023, 2024], array_column($results, 'year'));
        // Nothing actually changed — same income, same rules, recalculated twice.
        $this->assertFalse($results[0]['changed']);
        $this->assertFalse($results[1]['changed']);
    }

    public function test_deleting_an_earlier_year_ripples_into_a_later_year_on_recalculation(): void
    {
        $customer = $this->customer();

        IncomeData::create([
            'customer_id' => $customer->id, 'source' => 'sdi_fattura', 'transaction_date' => '2023-03-01',
            'sales_amount' => 50000.00, 'taxable_sales' => 50000.00, 'sale_taxes' => 0, 'tax_year' => 2023,
        ]);
        IncomeData::create([
            'customer_id' => $customer->id, 'source' => 'sdi_fattura', 'transaction_date' => '2024-03-01',
            'sales_amount' => 10000.00, 'taxable_sales' => 10000.00, 'sale_taxes' => 0, 'tax_year' => 2024,
        ]);

        $orchestrator = app(TaxCalculationOrchestrator::class);
        $orchestrator->runForCustomerAndYear($customer, 2023);
        $orchestrator->runForCustomerAndYear($customer, 2024);

        // 2023's tax due was high enough to schedule real advances toward 2024.
        $before = (float) TaxCalculation::where('customer_id', $customer->id)->where('tax_year', 2024)->first()->advance_payments_made;
        $this->assertGreaterThan(0, $before);

        // Nothing paid, so this is allowed — and it takes those 2024 advances with it.
        app(TaxYearDeletionService::class)->delete($customer, 2023);

        $results = $orchestrator->runForwardFromYear($customer, 2024);

        $this->assertCount(1, $results);
        $this->assertSame(2024, $results[0]['year']);
        $this->assertTrue($results[0]['changed']);
        $this->assertSame($before, $results[0]['before']['advance_payments_made']);
        $this->assertSame(0.0, $results[0]['after']['advance_payments_made']);
    }

    /**
     * TaxRules is one global switch (Setting), not per customer. A year's
     * figures were computed under whatever the switch was set to AT THE
     * TIME — recalculating later, after the global switch changed, must
     * not silently recompute under the new rule set and overwrite real
     * numbers with a different methodology.
     */
    public function test_rule_mismatch_is_detected_when_the_global_setting_changes_after_a_year_was_calculated(): void
    {
        $customer = $this->customer();

        IncomeData::create([
            'customer_id' => $customer->id, 'source' => 'sdi_fattura', 'transaction_date' => '2024-03-01',
            'sales_amount' => 50000.00, 'taxable_sales' => 50000.00, 'sale_taxes' => 0, 'tax_year' => 2024,
        ]);

        $orchestrator = app(TaxCalculationOrchestrator::class);
        $orchestrator->runForCustomerAndYear($customer, 2024);

        $this->assertNull($orchestrator->ruleMismatch($customer, 2024));

        Setting::set(TaxRules::SETTING, TaxRules::LEGACY, 'tax');

        $this->assertSame(TaxRules::LAW, $orchestrator->ruleMismatch($customer, 2024));
    }
}
