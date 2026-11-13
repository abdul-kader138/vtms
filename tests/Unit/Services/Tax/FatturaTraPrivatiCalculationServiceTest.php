<?php

namespace Tests\Unit\Services\Tax;

use App\Models\Customer;
use App\Models\IncomeData;
use App\Services\Tax\FatturaTraPrivatiCalculationService;
use Database\Seeders\TaxSettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FatturaTraPrivatiCalculationServiceTest extends TestCase
{
    use RefreshDatabase;

    private FatturaTraPrivatiCalculationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(TaxSettingsSeeder::class);
        $this->service = app(FatturaTraPrivatiCalculationService::class);
    }

    private function income(Customer $customer, string $source, float $amount, int $year = 2025): IncomeData
    {
        return IncomeData::create([
            'customer_id' => $customer->id,
            'source' => $source,
            'transaction_date' => "$year-05-01",
            'sales_amount' => $amount,
            'taxable_sales' => $amount,
            'sale_taxes' => 0,
            'tax_year' => $year,
        ]);
    }

    public function test_it_counts_qualifying_invoices_from_both_sources_fixing_the_vtms_undercount_bug(): void
    {
        $customer = Customer::create(['name' => 'Mixed Source Customer']);

        // 2 qualifying SDI-imported invoices (the source VTMS's original app
        // never counted) + 1 qualifying privati/device invoice.
        $this->income($customer, 'sdi_fattura', 100.00);
        $this->income($customer, 'sdi_fattura', 13500.00);
        $this->income($customer, 'privati_device', 90.00);
        // Below threshold — must NOT be counted, from either source.
        $this->income($customer, 'sdi_fattura', 50.00);
        $this->income($customer, 'privati_device', 60.00);

        $calc = $this->service->calculateForYear($customer, 2025);

        $this->assertNotNull($calc);
        $this->assertSame(3, $calc->total_invoices);
        $this->assertEqualsWithDelta(6.00, (float) $calc->total_payment_amount, 0.001);
    }

    public function test_it_matches_vtms_worked_examples_for_customers_169_and_11_shape(): void
    {
        $customerA = Customer::create(['name' => 'Customer 169 equivalent']);
        $this->income($customerA, 'privati_device', 100.00);
        $this->income($customerA, 'privati_device', 200.00);
        $this->income($customerA, 'sdi_fattura', 97.00);

        $calcA = $this->service->calculateForYear($customerA, 2025);
        $this->assertSame(3, $calcA->total_invoices);
        $this->assertEqualsWithDelta(6.00, (float) $calcA->total_payment_amount, 0.001);

        $customerB = Customer::create(['name' => 'Customer 11 equivalent']);
        $this->income($customerB, 'privati_device', 150.00);
        $this->income($customerB, 'sdi_fattura', 80.00);

        $calcB = $this->service->calculateForYear($customerB, 2025);
        $this->assertSame(2, $calcB->total_invoices);
        $this->assertEqualsWithDelta(4.00, (float) $calcB->total_payment_amount, 0.001);
    }

    public function test_no_qualifying_invoices_produces_no_calculation(): void
    {
        $customer = Customer::create(['name' => 'No Qualifying Invoices']);
        $this->income($customer, 'sdi_fattura', 10.00);
        $this->income($customer, 'privati_device', 20.00);

        $this->assertNull($this->service->calculateForYear($customer, 2025));
    }
}
