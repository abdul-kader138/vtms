<?php

namespace Tests\Feature\Console;

use App\Models\Customer;
use App\Models\IncomeData;
use App\Models\InpsPayment;
use App\Models\TaxYearLock;
use App\Services\Tax\TaxCalculationOrchestrator;
use Database\Seeders\InpsRateSlabSeeder;
use Database\Seeders\TaxSettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaxDeleteYearTest extends TestCase
{
    use RefreshDatabase;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(TaxSettingsSeeder::class);
        $this->seed(InpsRateSlabSeeder::class);

        $this->customer = Customer::create([
            'name' => 'Delete Year Command Test', 'customer_type' => 'gestione_separata',
            'coefficient_of_profitability' => 78.00, 'tax_rate' => 5.00,
        ]);

        IncomeData::create([
            'customer_id' => $this->customer->id, 'source' => 'sdi_fattura', 'transaction_date' => '2025-03-01',
            'sales_amount' => 10000.00, 'taxable_sales' => 10000.00, 'sale_taxes' => 0, 'tax_year' => 2025,
        ]);

        app(TaxCalculationOrchestrator::class)->runForCustomerAndYear($this->customer, 2025);
    }

    public function test_it_deletes_a_year_when_confirmed(): void
    {
        $this->artisan('tax:delete-year', ['customer' => $this->customer->id, 'year' => 2025, '--force' => true])
            ->assertSuccessful();

        $this->assertDatabaseMissing('tax_calculations', ['customer_id' => $this->customer->id, 'tax_year' => 2025]);
        $this->assertDatabaseMissing('inps_calculations', ['customer_id' => $this->customer->id, 'tax_year' => 2025]);
    }

    public function test_it_refuses_a_locked_year(): void
    {
        TaxYearLock::create(['customer_id' => $this->customer->id, 'tax_year' => 2025, 'locked_at' => now()]);

        $this->artisan('tax:delete-year', ['customer' => $this->customer->id, 'year' => 2025, '--force' => true])
            ->assertFailed();

        $this->assertDatabaseHas('tax_calculations', ['customer_id' => $this->customer->id, 'tax_year' => 2025]);
    }

    public function test_it_refuses_when_a_payment_has_already_been_touched(): void
    {
        InpsPayment::where('customer_id', $this->customer->id)->where('payment_year', 2025)->first()
            ->update(['status' => 'paid', 'paid_amount' => 1.00, 'paid_date' => '2025-06-30']);

        $this->artisan('tax:delete-year', ['customer' => $this->customer->id, 'year' => 2025, '--force' => true])
            ->assertFailed();

        $this->assertDatabaseHas('tax_calculations', ['customer_id' => $this->customer->id, 'tax_year' => 2025]);
    }

    public function test_it_is_a_no_op_when_the_year_has_no_data(): void
    {
        $this->artisan('tax:delete-year', ['customer' => $this->customer->id, 'year' => 1999, '--force' => true])
            ->assertSuccessful();
    }

    public function test_it_resolves_a_customer_by_vat_number(): void
    {
        $this->customer->update(['vat_no' => 'IT99999999999']);

        $this->artisan('tax:delete-year', ['customer' => 'IT99999999999', 'year' => 2025, '--force' => true])
            ->assertSuccessful();

        $this->assertDatabaseMissing('tax_calculations', ['customer_id' => $this->customer->id, 'tax_year' => 2025]);
    }
}
