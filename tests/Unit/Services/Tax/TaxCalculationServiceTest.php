<?php

namespace Tests\Unit\Services\Tax;

use App\Models\Customer;
use App\Models\IncomeData;
use App\Services\Tax\PaymentScheduleService;
use App\Services\Tax\TaxCalculationService;
use Database\Seeders\InpsRateSlabSeeder;
use Database\Seeders\TaxSettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaxCalculationServiceTest extends TestCase
{
    use RefreshDatabase;

    private TaxCalculationService $service;

    private PaymentScheduleService $scheduler;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(TaxSettingsSeeder::class);
        $this->seed(InpsRateSlabSeeder::class);
        $this->service = app(TaxCalculationService::class);
        $this->scheduler = app(PaymentScheduleService::class);
    }

    private function makeCustomer(array $overrides = []): Customer
    {
        return Customer::create(array_merge([
            'name' => 'Test Artigiano',
            'customer_type' => 'artigiani',
            'coefficient_of_profitability' => 78.00,
            'tax_rate' => 5.00,
            'business_start_date' => '2010-01-01',
        ], $overrides));
    }

    private function seedIncome(Customer $customer, int $year, float $taxableSales): void
    {
        IncomeData::create([
            'customer_id' => $customer->id,
            'source' => 'sdi_fattura',
            'transaction_date' => "$year-06-01",
            'sales_amount' => $taxableSales,
            'taxable_sales' => $taxableSales,
            'sale_taxes' => 0,
            'tax_year' => $year,
        ]);
    }

    public function test_it_calculates_2024_then_2025_matching_vtms_worked_examples_and_snapshots_the_coefficient(): void
    {
        $customer = $this->makeCustomer();

        // 2024: coefficient was 78% that year.
        $this->seedIncome($customer, 2024, 7032.84);
        $tax2024 = $this->service->calculateForYear($customer, 2024);

        $this->assertEqualsWithDelta(5485.62, (float) $tax2024->taxable_income, 0.001);
        $this->assertEqualsWithDelta(274.28, (float) $tax2024->tax_due, 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $tax2024->advance_payments_made, 0.001);
        $this->assertEqualsWithDelta(274.28, (float) $tax2024->balance_payment, 0.001);
        $this->assertEqualsWithDelta(219.42, (float) $tax2024->next_year_advance_base, 0.001);
        $this->assertEqualsWithDelta(78.00, (float) $tax2024->coefficient_used, 0.001);

        // Scheduling 2024's payments creates the two advances FOR 2025.
        $this->scheduler->scheduleTaxPayments($tax2024, $customer);

        // Customer's coefficient changes before the 2025 run.
        $customer->update(['coefficient_of_profitability' => 65.00]);
        $this->seedIncome($customer, 2025, 18401.67);
        $tax2025 = $this->service->calculateForYear($customer, 2025);

        $this->assertEqualsWithDelta(11961.09, (float) $tax2025->taxable_income, 0.001);
        $this->assertEqualsWithDelta(598.05, (float) $tax2025->tax_due, 0.001);
        $this->assertEqualsWithDelta(219.42, (float) $tax2025->advance_payments_made, 0.001);
        $this->assertEqualsWithDelta(378.63, (float) $tax2025->balance_payment, 0.001);
        $this->assertEqualsWithDelta(478.44, (float) $tax2025->next_year_advance_base, 0.001);

        // Snapshot proof: 2024's stored coefficient_used must still be 78,
        // even though the customer's current setting is now 65.
        $tax2024->refresh();
        $this->assertEqualsWithDelta(78.00, (float) $tax2024->coefficient_used, 0.001);
        $this->assertEqualsWithDelta(65.00, (float) $tax2025->coefficient_used, 0.001);
    }

    public function test_zero_sales_year_produces_zero_tax_due_and_no_advance_payments(): void
    {
        $customer = $this->makeCustomer();
        $tax = $this->service->calculateForYear($customer, 2025);

        $this->assertEqualsWithDelta(0.0, (float) $tax->total_sales, 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $tax->taxable_income, 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $tax->tax_due, 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $tax->balance_payment, 0.001);

        $this->scheduler->scheduleTaxPayments($tax, $customer);

        // The zero-amount balance is still recorded for the year (not
        // skipped — only the business-start-year itself is skipped), but no
        // advance rows are created since advanceEach is 0.
        $this->assertDatabaseCount('tax_payments', 1);
        $this->assertDatabaseHas('tax_payments', [
            'customer_id' => $customer->id,
            'payment_type' => 'balance',
            'tax_year' => 2025,
        ]);
    }

    public function test_balance_payment_is_skipped_in_the_customers_business_start_year(): void
    {
        $customer = $this->makeCustomer(['business_start_date' => '2025-03-01']);
        $this->seedIncome($customer, 2025, 10000);
        $tax = $this->service->calculateForYear($customer, 2025);

        $this->scheduler->scheduleTaxPayments($tax, $customer);

        $this->assertDatabaseMissing('tax_payments', [
            'customer_id' => $customer->id,
            'payment_type' => 'balance',
            'tax_year' => 2025,
        ]);
    }
}
