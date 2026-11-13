<?php

namespace Tests\Unit\Services\Tax;

use App\Models\Customer;
use App\Models\DirittoAnnualeBracket;
use App\Models\IncomeData;
use App\Services\Tax\TaxCalculationOrchestrator;
use Database\Seeders\InpsRateSlabSeeder;
use Database\Seeders\TaxSettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaxCalculationOrchestratorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(TaxSettingsSeeder::class);
        $this->seed(InpsRateSlabSeeder::class);
    }

    public function test_it_runs_all_five_calculators_and_schedules_payments_in_one_pass(): void
    {
        $customer = Customer::create([
            'name' => 'Full Pipeline Artigiano',
            'customer_type' => 'artigiani',
            'coefficient_of_profitability' => 65.00,
            'tax_rate' => 5.00,
            'inail_rate' => 5.00,
            'inail_minimum_payment' => 210.00,
            'inps_discount_eligible' => true,
            'business_start_date' => '2010-01-01',
        ]);

        DirittoAnnualeBracket::create(['bracket_year' => 2025, 'revenue_from' => 0, 'revenue_to' => null, 'amount' => 53.00]);

        IncomeData::create([
            'customer_id' => $customer->id, 'source' => 'sdi_fattura', 'transaction_date' => '2025-03-01',
            'sales_amount' => 18401.67, 'taxable_sales' => 18401.67, 'sale_taxes' => 0, 'tax_year' => 2025,
        ]);
        IncomeData::create([
            'customer_id' => $customer->id, 'source' => 'privati_device', 'transaction_date' => '2025-04-01',
            'sales_amount' => 100.00, 'taxable_sales' => 0, 'sale_taxes' => 0, 'tax_year' => 2025,
        ]);

        $result = app(TaxCalculationOrchestrator::class)->runForCustomerAndYear($customer, 2025);

        $this->assertEqualsWithDelta(11961.09, (float) $result['tax']->taxable_income, 0.001);
        $this->assertEqualsWithDelta(1583.35, (float) $result['inps']->inps_amount_after_discount, 0.001);
        $this->assertEqualsWithDelta(598.05, (float) $result['inail']->inail_final_amount, 0.001);
        $this->assertEqualsWithDelta(53.00, (float) $result['diritto_annuale_bracket']->amount, 0.001);
        // Both seeded income rows have sales_amount >= the €77.47 threshold
        // (18401.67 and 100.00), from different sources — both must count.
        $this->assertSame(2, $result['fattura_tra_privati']->total_invoices);

        $this->assertDatabaseHas('tax_payments', ['customer_id' => $customer->id, 'payment_type' => 'balance']);
        $this->assertCount(4, $customer->inpsPayments()->get());
        $this->assertDatabaseHas('inail_payments', ['customer_id' => $customer->id]);
        $this->assertDatabaseHas('diritto_annuale_payments', ['customer_id' => $customer->id]);
        $this->assertDatabaseHas('fattura_tra_privati_payments', ['customer_id' => $customer->id]);

        // Re-running is idempotent — no duplicate rows.
        app(TaxCalculationOrchestrator::class)->runForCustomerAndYear($customer, 2025);
        $this->assertCount(4, $customer->inpsPayments()->get());
        $this->assertSame(1, $customer->taxCalculations()->where('tax_year', 2025)->count());
    }
}
